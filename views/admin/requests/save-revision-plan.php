<?php
declare(strict_types=1);

require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/RevisionPlan.php';

$conn = db();
$base = rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/');
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$versionId = (int) ($_POST['version_id'] ?? 0);
$templateId = (int) ($_POST['template_id'] ?? 0);

$redirect = static function (bool $ok, string $message, int $templateId = 0, int $versionId = 0, ?int $focusBatch = null) use ($base): void {
    $_SESSION['revision_plan_flash'] = ['ok' => $ok, 'message' => $message];
    $query = [];
    if ($templateId > 0) $query['template_id'] = $templateId;
    if ($versionId > 0) $query['version_id'] = $versionId;
    if ($focusBatch !== null && $focusBatch >= 0) $query['focus_batch'] = $focusBatch;
    header('Location: ' . $base . '/admin/revision-plans' . ($query ? '?' . http_build_query($query) : ''));
    exit;
};

// The Admin route and Revision Plan forms use the canonical Admin CSRF token.
// Validate the submitted token explicitly before any mutation; passing the
// field is required because the Auth CSRF helper has a mandatory argument and
// is a different token namespace.
if (!mmh_admin_csrf_valid($_POST['_token'] ?? '')) $redirect(false, 'Your session has expired. Refresh and try again.', $templateId, $versionId);

try {
    $adminId = mmh_auth_user_id($conn, (string) ($_SESSION['admin'] ?? ''));
    if ($adminId <= 0) throw new RuntimeException('Administrator identity could not be verified.');

    if ($action === 'create_template') {
        $courseId = trim((string) ($_POST['course_id'] ?? ''));
        $newTemplateId = mmh_revision_create_template(
            $conn,
            $courseId,
            mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 180),
            mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 1000),
            $adminId
        );
        $newVersionId = mmh_revision_latest_version_id($conn, $newTemplateId);
        $redirect(true, 'Revision Plan template created.', $newTemplateId, $newVersionId);
    }

    if ($action === 'prepare_batch_edit') {
        $version = mmh_revision_version($conn, $versionId);
        if (!$version || (int) ($version['template_id'] ?? 0) !== $templateId) throw new InvalidArgumentException('The selected Revision Plan version could not be found.');
        $batchPosition = (int) ($_POST['batch_position'] ?? -1);
        if ($batchPosition < 0 || !array_key_exists($batchPosition, (array) ($version['batches'] ?? []))) throw new InvalidArgumentException('Batch not found.');
        $draftId = mmh_revision_prepare_editable_version($conn, $templateId, $versionId, $adminId);
        $redirect(true, $draftId === $versionId ? 'Batch is ready to edit.' : 'A new Draft Version is ready to edit this Batch. The released Version remains unchanged.', $templateId, $draftId, $batchPosition);
    }

    if ($action === 'add_batch') {
        $version = mmh_revision_version($conn, $versionId);
        if (!$version || (int) ($version['template_id'] ?? 0) !== $templateId) throw new InvalidArgumentException('The selected Revision Plan version could not be found.');
        $draftId = mmh_revision_prepare_editable_version($conn, $templateId, $versionId, $adminId);
        $draft = mmh_revision_version($conn, $draftId);
        if (!$draft) throw new RuntimeException('The editable Draft Version could not be loaded.');
        if ($sourceWasDraft) {
            $json = trim((string) ($_POST['structure_json'] ?? ''));
            $structure = $json === '' ? ['batches' => ($draft['batches'] ?? [])] : json_decode($json, true);
            if (!is_array($structure)) throw new InvalidArgumentException('The Batch structure is invalid.');
        } else {
            // Published content is cloned from its immutable source. Ignore
            // client structure data so old resource IDs cannot cross Versions.
            $structure = ['batches' => ($draft['batches'] ?? [])];
        }
        $batches = is_array($structure['batches'] ?? null) ? $structure['batches'] : [];
        $title = mb_substr(trim((string) ($_POST['batch_title'] ?? 'New Batch')), 0, 180);
        if ($title === '') $title = 'New Batch';
        $description = mb_substr(trim((string) ($_POST['batch_description'] ?? '')), 0, 1000);
        $dayCount = max(0, min(30, (int) ($_POST['batch_days'] ?? 0)));
        $days = [];
        for ($i = 0; $i < $dayCount; $i++) $days[] = ['day_number' => $i + 1, 'title' => 'Day ' . ($i + 1), 'description' => '', 'sort_order' => $i, 'requirements' => [], 'activity_groups' => []];
        $batches[] = ['title' => $title, 'description' => $description, 'suggested_days' => $dayCount, 'day_access_mode' => 'follow_schedule', 'schedule_mode' => 'automatic', 'sort_order' => count($batches), 'days' => $days];
        $structure['batches'] = $batches;
        $draftTemplate = mmh_revision_template($conn, $templateId);
        if (!$draftTemplate) throw new RuntimeException('The Revision Plan template could not be loaded.');
        mmh_revision_save_draft($conn, $draftId, $structure, (string) ($draftTemplate['title'] ?? $draft['template_title'] ?? ''), (string) ($draftTemplate['description'] ?? ''), !empty($draft['allow_work_ahead']));
        $redirect(true, 'Coming Soon Batch added. Add its content when ready.', $templateId, $draftId, count($batches) - 1);
    }

    if ($action === 'save_version') {
        $template = mmh_revision_template($conn, $templateId);
        $version = mmh_revision_version($conn, $versionId);
        if (!$template || !$version || (int) $version['template_id'] !== $templateId) throw new InvalidArgumentException('The selected Revision Plan version could not be found.');
        $json = (string) ($_POST['structure_json'] ?? '');
        if ($json === '' || strlen($json) > 5 * 1024 * 1024) throw new InvalidArgumentException('The template structure is invalid.');
        $structure = json_decode($json, true);
        if (!is_array($structure)) throw new InvalidArgumentException('The template structure is invalid.');
        mmh_revision_save_draft(
            $conn,
            $versionId,
            $structure,
            mb_substr(trim((string) ($_POST['title'] ?? $template['title'])), 0, 180),
            mb_substr(trim((string) ($_POST['description'] ?? $template['description'] ?? '')), 0, 1000),
            !empty($_POST['allow_work_ahead'])
        );
        $redirect(true, 'Draft Version saved.', $templateId, $versionId);
    }

    if ($action === 'publish_version') {
        $version = mmh_revision_version($conn, $versionId);
        if (!$version) throw new InvalidArgumentException('Version not found.');
        $template = mmh_revision_template($conn, (int) $version['template_id']);
        $json = (string) ($_POST['structure_json'] ?? '');
        if ($json !== '') {
            $structure = json_decode($json, true);
            if (!is_array($structure)) throw new InvalidArgumentException('The template structure is invalid.');
            mmh_revision_save_draft($conn, $versionId, $structure, mb_substr(trim((string) ($_POST['title'] ?? $template['title'] ?? '')), 0, 180), mb_substr(trim((string) ($_POST['description'] ?? $template['description'] ?? '')), 0, 1000), !empty($_POST['allow_work_ahead']));
        }
        mmh_revision_publish_version($conn, $versionId, $adminId);
        $redirect(true, 'Revision Plan published.', (int) $version['template_id'], $versionId);
    }

    if ($action === 'publish_and_assign') {
        $template = mmh_revision_template($conn, $templateId);
        $version = mmh_revision_version($conn, $versionId);
        if (!$template || !$version || (int) $version['template_id'] !== $templateId) throw new InvalidArgumentException('The selected Revision Plan version could not be found.');
        if ((string) $version['status'] !== 'draft') throw new InvalidArgumentException('This plan is already published. Create a new version to change it.');
        $json = (string) ($_POST['structure_json'] ?? '');
        $structure = json_decode($json, true);
        if (!is_array($structure)) throw new InvalidArgumentException('The plan structure is invalid.');
        mmh_revision_save_draft(
            $conn,
            $versionId,
            $structure,
            mb_substr(trim((string) ($_POST['title'] ?? $template['title'])), 0, 180),
            mb_substr(trim((string) ($_POST['description'] ?? $template['description'] ?? '')), 0, 1000),
            !empty($_POST['allow_work_ahead'])
        );
        mmh_revision_publish_version($conn, $versionId, $adminId);
        try {
            $assigned = mmh_revision_assign_students($conn, $versionId, (array) ($_POST['student_ids'] ?? []), (string) ($_POST['start_date'] ?? ''), $adminId);
        } catch (Throwable $assignmentError) {
            throw new RuntimeException('The Version was published, but student assignment failed. Open the published plan and assign students again.');
        }
        if ($assigned < 1) throw new InvalidArgumentException('The Version was published, but no new students were assigned.');
        $redirect(true, 'Published and assigned to ' . $assigned . ' student' . ($assigned === 1 ? '' : 's') . '.', $templateId, $versionId);
    }

    if ($action === 'assign_students') {
        $versionId = (int) ($_POST['version_id'] ?? 0);
        $assigned = mmh_revision_assign_students($conn, $versionId, (array) ($_POST['student_ids'] ?? []), (string) ($_POST['start_date'] ?? ''), $adminId);
        $redirect(true, $assigned > 0 ? $assigned . ' student' . ($assigned === 1 ? '' : 's') . ' assigned.' : 'Those students already have this Version assigned.', (int) ($_POST['template_id'] ?? 0), $versionId);
    }

    if ($action === 'update_batch_controls') {
        if ($templateId <= 0) throw new InvalidArgumentException('Revision Plan not found.');
        $batchPosition = (int) ($_POST['batch_position'] ?? -1);
        if ($batchPosition < 0) throw new InvalidArgumentException('Batch not found.');
        $visibility = strtolower(trim((string) ($_POST['batch_visibility'] ?? 'coming_soon')));
        if (!in_array($visibility, ['released', 'coming_soon'], true)) throw new InvalidArgumentException('Choose a valid Batch visibility.');
        $title = mb_substr(trim((string) ($_POST['batch_title'] ?? '')), 0, 180);
        if ($title === '') throw new InvalidArgumentException('Enter a Batch name.');
        $dayAccess = strtolower(trim((string) ($_POST['day_access_mode'] ?? 'follow_schedule')));
        if (!in_array($dayAccess, ['follow_schedule', 'open_all'], true)) $dayAccess = 'follow_schedule';
        $scheduleMode = strtolower(trim((string) ($_POST['schedule_mode'] ?? 'automatic')));
        if (!in_array($scheduleMode, ['automatic', 'manual'], true)) $scheduleMode = 'automatic';
        $scheduleStartDate = mmh_revision_normalize_study_date($_POST['schedule_start_date'] ?? '', false);
        $version = mmh_revision_version($conn, $versionId);
        if (!$version || (int) ($version['template_id'] ?? 0) !== $templateId) throw new InvalidArgumentException('The selected Revision Plan version could not be found.');
        $batches = (array) ($version['batches'] ?? []);
        if (!array_key_exists($batchPosition, $batches)) throw new InvalidArgumentException('Batch not found.');
        $releaseStatuses = mmh_revision_batch_release_statuses($conn, $templateId);
        $hasRelease = isset($releaseStatuses[$batchPosition]);
        if ($hasRelease) {
            // Released rows store only visibility metadata. Toggling them never
            // mutates the immutable Version or deletes student state.
            mmh_revision_update_batch_controls($conn, $templateId, $batchPosition, $title, $visibility, $dayAccess, $versionId);
            $redirect(true, $visibility === 'released' ? 'Batch is now Released.' : 'Batch is now Coming Soon. Student progress and assignments were preserved.', $templateId, $versionId, $batchPosition);
        }

        // A shell has no release row yet. Persist its title/access settings in
        // a Draft; if the admin chose Released, the existing publish service
        // performs the atomic validation and release of the complete Batch.
        $draftId = mmh_revision_prepare_editable_version($conn, $templateId, $versionId, $adminId);
        $draft = mmh_revision_version($conn, $draftId);
        if (!$draft) throw new RuntimeException('The editable Draft Version could not be loaded.');
        $json = trim((string) ($_POST['structure_json'] ?? ''));
        $structure = $json === '' ? ['batches' => ($draft['batches'] ?? [])] : json_decode($json, true);
        if (!is_array($structure)) throw new InvalidArgumentException('The Batch structure is invalid.');
        $draftBatches = is_array($structure['batches'] ?? null) ? $structure['batches'] : [];
        if (!array_key_exists($batchPosition, $draftBatches)) throw new InvalidArgumentException('Batch not found.');
        $draftBatches[$batchPosition]['title'] = $title;
        $draftBatches[$batchPosition]['day_access_mode'] = $dayAccess;
        $draftBatches[$batchPosition]['schedule_mode'] = $scheduleMode;
        $draftBatches[$batchPosition]['schedule_start_date'] = $scheduleStartDate;
        $structure['batches'] = $draftBatches;
        $template = mmh_revision_template($conn, $templateId);
        if (!$template) throw new RuntimeException('The Revision Plan template could not be loaded.');
        mmh_revision_save_draft($conn, $draftId, $structure, (string) ($template['title'] ?? ''), (string) ($template['description'] ?? ''), !empty($draft['allow_work_ahead']));
        if ($visibility === 'released') {
            mmh_revision_publish_version($conn, $draftId, $adminId);
            $redirect(true, 'Batch is now Released.', $templateId, $draftId, $batchPosition);
        }
        $redirect(true, 'Batch is now Coming Soon. Add its content when ready.', $templateId, $draftId, $batchPosition);
    }

    if ($action === 'new_version') {
        $sourceId = $versionId > 0 ? $versionId : mmh_revision_latest_version_id($conn, $templateId);
        $newVersionId = mmh_revision_clone_version($conn, $sourceId, $adminId);
        $source = mmh_revision_version($conn, $sourceId);
        $redirect(true, 'New Draft Version created. The previous version was not changed.', (int) ($source['template_id'] ?? $templateId), $newVersionId);
    }

    if ($action === 'archive_template') {
        if ($templateId <= 0) throw new InvalidArgumentException('Template not found.');
        mmh_revision_archive_template($conn, $templateId);
        $redirect(true, 'Revision Plan template archived.', $templateId);
    }

    if ($action === 'delete_template') {
        if ($templateId <= 0) throw new InvalidArgumentException('Revision Plan not found.');
        $confirmed = strtoupper(trim((string) ($_POST['delete_confirmation'] ?? ''))) === 'DELETE';
        $hasActivity = mmh_revision_template_has_student_activity($conn, $templateId);
        mmh_revision_delete_template($conn, $templateId, !$hasActivity || $confirmed);
        $redirect(true, 'Revision Plan deleted.');
    }

    if ($action === 'add_resource') {
        $version = mmh_revision_version($conn, $versionId);
        if (!$version) throw new InvalidArgumentException('Version not found.');
        $resourceId = mmh_revision_save_resource($conn, $versionId, $adminId, $_POST, $_FILES['resource_file'] ?? []);
        $redirect(true, 'Shared material added.', (int) $version['template_id'], $versionId);
    }

    if ($action === 'delete_resource') {
        $resourceId = (int) ($_POST['resource_id'] ?? 0);
        $resource = mmh_revision_resource($conn, $resourceId);
        if (!$resource) throw new InvalidArgumentException('Resource not found.');
        mmh_revision_delete_resource($conn, $resourceId);
        $redirect(true, 'Shared material removed.', (int) $resource['template_id'], $versionId ?: (int) ($resource['version_id'] ?? 0));
    }

    throw new InvalidArgumentException('Unsupported Revision Plan action.');
} catch (Throwable $exception) {
    $safeMessage = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The Revision Plan change could not be saved.';
    $redirect(false, $safeMessage, $templateId, $versionId);
}
