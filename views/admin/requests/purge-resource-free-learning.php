<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php'; mmh_free_require_admin_csrf();
$conn = db(); $resource = mmh_free_resource($conn, $_POST['resource_id'] ?? '');
if (!$resource || $resource['status'] !== 'archived') { mmh_free_flash('error', 'Only archived resources can be permanently deleted.'); }
else { $relations = mmh_free_resource_relation_count($conn, $resource['resource_id']); if ($relations && empty($_POST['confirm_related'])) { mmh_free_flash('error', 'This resource has related-resource links. Confirm permanent deletion to continue.'); } else { [$ok,$message] = mmh_free_delete_resource($conn, $resource['resource_id'], !empty($_POST['delete_uploaded_file'])); mmh_free_flash($ok ? 'success' : 'error', $message); } }
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/free-learning?status=archived'); exit;
