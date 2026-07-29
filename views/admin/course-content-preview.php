<?php
/**
 * Read-only Admin Course Content preview.
 *
 * This route intentionally does not reuse the student endpoint: admins are
 * not required to be enrolled, and a preview must never create progress or
 * learning-event records. It does reuse CourseResourceResolver so every
 * structured and legacy item receives the same embed/redirect/render choice.
 */
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/CourseResourceResolver.php';

$pageName = 'courses';
$subPageName = 'courses';
$conn = db();
$courseId = trim((string) ($courseId ?? ''));
$itemId = trim((string) ($itemId ?? ''));

function admin_content_preview_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_content_preview_identifier($value): bool
{
    return $value !== '' && (bool) preg_match('/^[A-Za-z0-9_-]{1,80}$/', $value);
}

if (!admin_content_preview_identifier($courseId) || !admin_content_preview_identifier($itemId)) {
    http_response_code(404);
    exit('Content preview not found.');
}

$courseStmt = $conn->prepare('SELECT course_id, course_title FROM courses WHERE course_id = ? LIMIT 1');
if (!$courseStmt) {
    http_response_code(500);
    exit('Unable to load course preview.');
}
$courseStmt->bind_param('s', $courseId);
$courseStmt->execute();
$course = $courseStmt->get_result()->fetch_assoc();
$courseStmt->close();
if (!$course) {
    http_response_code(404);
    exit('Course not found.');
}

$itemStmt = $conn->prepare('SELECT i.*, s.metadata AS section_metadata FROM course_items AS i LEFT JOIN course_sections AS s ON s.course_id = i.course_id AND s.section_id = i.section_id WHERE i.course_id = ? AND i.item_id = ? LIMIT 1');
if (!$itemStmt) {
    http_response_code(500);
    exit('Unable to load content preview.');
}
$itemStmt->bind_param('ss', $courseId, $itemId);
$itemStmt->execute();
$item = $itemStmt->get_result()->fetch_assoc();
$itemStmt->close();
if (!$item) {
    http_response_code(404);
    exit('Content item not found.');
}

$resource = mmh_course_resource_resolve($item);
$action = (string) ($resource['action'] ?? 'unavailable');
$title = (string) ($item['item_title'] ?? 'Untitled lesson');
$label = (string) ($resource['label'] ?? 'Learning Material');
$icon = (string) ($resource['icon'] ?? 'fas fa-file-alt');
$status = strtolower(trim((string) ($item['status'] ?? 'published')));
$status = $status === '' ? 'published' : $status;
$statusLabel = $status === 'published' ? 'Published' : ucfirst($status);
$base = rtrim((string) $baseUrl, '/');
$returnUrl = $base . '/admin/courses/' . rawurlencode($courseId) . '/content#course-item-' . rawurlencode($itemId);
$editUrl = $base . '/admin/courses/' . rawurlencode($courseId) . '/content#course-item-' . rawurlencode($itemId);
$openUrl = (string) ($resource['open_url'] ?? $resource['url'] ?? '');
$embedUrl = (string) ($resource['embed_url'] ?? '');
$description = trim((string) ($resource['description'] ?? ''));
$richHtml = (string) ($item['item_description'] ?? '');
$homeworkPreview = is_array($resource['homework_resource'] ?? null) ? $resource['homework_resource'] : ['url' => $resource['homework_url'] ?? ''];
$homeworkPreviewUrl = mmh_course_resource_safe_url($homeworkPreview['url'] ?? '');
$homeworkPreviewType = (string) ($homeworkPreview['resource_type'] ?? mmh_course_resource_type_for_url($homeworkPreviewUrl, 'external_link'));
$homeworkPreviewEmbed = $homeworkPreviewUrl ? mmh_course_resource_embed_details($homeworkPreviewUrl, $homeworkPreviewType) : null;
$modelPreview = is_array($resource['model_answer_resource'] ?? null) ? $resource['model_answer_resource'] : null;
$modelPreviewUrl = mmh_course_resource_safe_url($modelPreview['url'] ?? '');
$modelPreviewType = (string) ($modelPreview['resource_type'] ?? mmh_course_resource_type_for_url($modelPreviewUrl, 'external_link'));
$modelPreviewEmbed = $modelPreviewUrl ? mmh_course_resource_embed_details($modelPreviewUrl, $modelPreviewType) : null;
$modelPreviewRelease = (string) ($modelPreview['release'] ?? 'hidden');
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= admin_content_preview_escape($base . '/admin/'); ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview <?= admin_content_preview_escape($title); ?> | <?= admin_content_preview_escape($site_name); ?></title>
    <?php include 'layouts/admin/header.php'; ?>
    <link rel="stylesheet" href="<?= admin_content_preview_escape($base); ?>/resources/css/course-manager.css">
</head>
<body class="dash ds-bg-primary">
<div class="col-12 d-flex">
    <?php include 'layouts/admin/aside.php'; ?>
    <div class="main-content in-active course-manager-main">
        <?php include 'layouts/admin/top-nav.php'; ?>
        <main class="course-manager-page course-content-preview-page">
            <nav class="course-content-preview-breadcrumb" aria-label="Breadcrumb">
                <a href="<?= admin_content_preview_escape($returnUrl); ?>"><i class="fas fa-arrow-left ds-icon ds-icon-sm" aria-hidden="true"></i> Back to Course Content</a>
                <span aria-hidden="true">/</span>
                <span><?= admin_content_preview_escape((string) $course['course_title']); ?></span>
            </nav>
            <header class="course-content-preview-header">
                <div>
                    <p class="course-manager-eyebrow">Student-view preview</p>
                    <h1><i class="<?= admin_content_preview_escape($icon); ?> ds-icon ds-icon-md" aria-hidden="true"></i> <?= admin_content_preview_escape($title); ?></h1>
                    <p><?= admin_content_preview_escape($label); ?> <span aria-hidden="true">•</span> <span class="course-manager-row-badge course-manager-status-<?= admin_content_preview_escape($status); ?>"><?= admin_content_preview_escape($statusLabel); ?></span></p>
                </div>
                <div class="course-content-preview-actions">
                    <a class="btn btn-outline-secondary" href="<?= admin_content_preview_escape($editUrl); ?>"><i class="fas fa-pen ds-icon ds-icon-sm" aria-hidden="true"></i> Edit</a>
                    <a class="btn btn-primary" href="<?= admin_content_preview_escape($returnUrl); ?>">Return to Content</a>
                </div>
            </header>

            <section class="course-content-preview-stage" aria-label="Lesson preview">
                <?php if ($action === 'embed' && $embedUrl !== ''): ?>
                    <iframe class="course-content-preview-embed" src="<?= admin_content_preview_escape($embedUrl); ?>" title="<?= admin_content_preview_escape($title); ?> preview" loading="eager" referrerpolicy="no-referrer" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>
                <?php elseif ($action === 'redirect' && $openUrl !== ''): ?>
                    <div class="course-content-preview-external">
                        <i class="fas fa-external-link-alt ds-icon ds-icon-lg" aria-hidden="true"></i>
                        <h2>This resource opens externally</h2>
                        <p>The provider does not support a safe in-site preview. The student’s protected resource flow remains unchanged.</p>
                        <a class="btn btn-primary" href="<?= admin_content_preview_escape($openUrl); ?>" target="_blank" rel="noopener noreferrer">Open external resource <i class="fas fa-arrow-up ds-icon ds-icon-sm" aria-hidden="true"></i></a>
                    </div>
                <?php elseif ($action === 'homework'): ?>
                    <div class="course-content-preview-external course-content-preview-homework">
                        <i class="fas fa-clipboard-list ds-icon ds-icon-lg" aria-hidden="true"></i>
                        <h2>Homework lesson</h2>
                        <p>Read-only preview: students receive both protected resource actions and the existing assignment upload workflow from one lesson.</p>
                        <span class="course-manager-row-badge course-manager-status-<?= admin_content_preview_escape($status); ?>">Assignment <?= admin_content_preview_escape((string) ($resource['assignment_id'] ?? '')) ?></span>
                        <div class="course-content-preview-homework-grid">
                            <section><h3><i class="fas fa-file-alt ds-icon ds-icon-sm" aria-hidden="true"></i> Homework resource</h3><?php if ($homeworkPreviewEmbed): ?><iframe class="course-content-preview-embed" src="<?= admin_content_preview_escape($homeworkPreviewEmbed['embed_url']); ?>" title="Homework resource preview" loading="lazy" referrerpolicy="no-referrer" allow="fullscreen" allowfullscreen></iframe><?php elseif ($homeworkPreviewUrl): ?><p>Opens through the protected external fallback.</p><?php else: ?><p>Homework file not configured.</p><?php endif; ?></section>
                            <section><h3><i class="fas fa-graduation-cap ds-icon ds-icon-sm" aria-hidden="true"></i> Model Answer</h3><p>Release: <?= admin_content_preview_escape(ucwords(str_replace('_', ' ', $modelPreviewRelease))); ?></p><?php if ($modelPreviewEmbed): ?><iframe class="course-content-preview-embed" src="<?= admin_content_preview_escape($modelPreviewEmbed['embed_url']); ?>" title="Model Answer preview" loading="lazy" referrerpolicy="no-referrer" allow="fullscreen" allowfullscreen></iframe><?php elseif ($modelPreviewUrl): ?><p>Opens through the protected external fallback.</p><?php else: ?><p>No Model Answer is configured.</p><?php endif; ?></section>
                        </div>
                        <section class="course-content-preview-homework-upload"><h3><i class="fas fa-upload ds-icon ds-icon-sm" aria-hidden="true"></i> Student upload</h3><p>Read-only in Admin Preview. No submission or student progress is created here.</p><button class="btn btn-outline-secondary" type="button" disabled>Upload Homework</button></section>
                    </div>
                <?php elseif ($action === 'render' && $richHtml !== ''): ?>
                    <iframe class="course-content-preview-rich" sandbox="" srcdoc="<?= admin_content_preview_escape($richHtml); ?>" title="<?= admin_content_preview_escape($title); ?> rich lesson preview"></iframe>
                <?php else: ?>
                    <div class="course-content-preview-external">
                        <i class="fas fa-info-circle ds-icon ds-icon-lg" aria-hidden="true"></i>
                        <h2>Preview unavailable</h2>
                        <p><?= admin_content_preview_escape((string) ($resource['reason'] ?? 'This item does not yet have previewable content.')); ?></p>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($description !== ''): ?>
                <section class="course-content-preview-description"><h2>Teacher description</h2><p><?= admin_content_preview_escape($description); ?></p></section>
            <?php endif; ?>
            <p class="course-content-preview-note"><i class="fas fa-shield-alt ds-icon ds-icon-sm" aria-hidden="true"></i> This is a read-only Admin preview. It does not create student progress, completion, or learning events.</p>
        </main>
    </div>
</div>
</body>
</html>
