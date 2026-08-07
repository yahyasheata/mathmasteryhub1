<?php
/* Compatibility overview only. Assignment definitions are owned by the
 * Assignment course element and are managed from Course Content. */
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AdminAssessmentService.php';

$username = $_SESSION['admin'] ?? '';
$pageName = 'courses';
$subPageName = 'assignments';
$conn = db();
mmh_ensure_learning_schema($conn);
$assignment_rows = mmh_admin_assignment_rows($conn);
$submission_counts = mmh_admin_assignment_submission_counts($conn);
$canonical_item_map = mmh_admin_assignment_item_map($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Assignment overview | <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php include 'layouts/admin/header.php'; ?>
</head>
<body class="dash ds-bg-primary">
  <div class="col-12 d-flex">
    <?php include 'layouts/admin/aside.php'; ?>
    <div class="main-content in-active">
      <?php include 'layouts/admin/top-nav.php'; ?>
      <main class="container-fluid py-4" style="margin-top:55px">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
          <div>
            <div class="small text-uppercase fw-bold ds-text-muted">Compatibility view</div>
            <h1 class="h3 mb-1"><span class="fas fa-tasks me-2" aria-hidden="true"></span>Assignment overview</h1>
            <p class="ds-text-muted mb-0">Create and edit assignments from the Assignment element inside Course Content.</p>
          </div>
          <a href="courses" class="btn btn-primary"><span class="fas fa-book-open me-1" aria-hidden="true"></span>Open Course Content</a>
        </div>

        <div class="alert ds-surface ds-border ds-text-secondary" role="note">
          This page is read-only for historical and operational reporting. It does not create, edit, archive, or delete assignment definitions.
        </div>

        <section class="ds-surface ds-border ds-shadow-sm rounded-3 p-3" aria-labelledby="assignment-overview-title">
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <h2 id="assignment-overview-title" class="h5 mb-0">Assignment records</h2>
            <span class="small ds-text-muted"><?= count($assignment_rows); ?> records</span>
          </div>
          <div class="table-responsive">
            <table class="table align-middle" dir="ltr">
              <thead><tr><th>Title</th><th>Course</th><th>Due date</th><th>Submissions</th><th>Record</th></tr></thead>
              <tbody>
              <?php if (!$assignment_rows): ?>
                <tr><td colspan="5" class="text-center ds-text-muted py-4">No assignment records found.</td></tr>
              <?php else: foreach ($assignment_rows as $assignment):
                $assignment_id = (string) ($assignment['assignment_id'] ?? '');
                $item = $canonical_item_map[$assignment_id] ?? null;
                $course_id = (string) ($assignment['course_id'] ?? '');
                $submission_url = 'assignment-submissions?assignment_id=' . rawurlencode($assignment_id);
                $content_url = $item ? 'courses/' . rawurlencode($course_id) . '/content#course-item-' . (int) ($item['item_db_id'] ?? 0) : '';
              ?>
                <tr>
                  <td><strong><?= htmlspecialchars((string) ($assignment['assignment_title'] ?? 'Untitled assignment'), ENT_QUOTES, 'UTF-8'); ?></strong><div class="small ds-text-muted">ID <?= htmlspecialchars($assignment_id, ENT_QUOTES, 'UTF-8'); ?></div></td>
                  <td><?= htmlspecialchars($course_id, ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?= htmlspecialchars((string) ($assignment['due_date'] ?? 'No due date'), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?= (int) ($submission_counts[$assignment_id] ?? 0); ?></td>
                  <td class="text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($submission_url, ENT_QUOTES, 'UTF-8'); ?>"><span class="fas fa-list me-1" aria-hidden="true"></span>Submissions</a>
                    <?php if ($content_url !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($content_url, ENT_QUOTES, 'UTF-8'); ?>"><span class="fas fa-edit me-1" aria-hidden="true"></span>Open element</a><?php else: ?><span class="badge bg-secondary">Legacy / archived</span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </main>
    </div>
  </div>
</body>
</html>
