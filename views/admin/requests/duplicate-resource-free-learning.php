<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php';
mmh_free_require_admin_csrf();
$options = ['copy_relations' => !empty($_POST['copy_relations']), 'copy_access' => !empty($_POST['copy_access']), 'copy_featured' => !empty($_POST['copy_featured']), 'copy_published' => !empty($_POST['copy_published'])];
[$ok, $message, $data] = array_pad(mmh_free_duplicate_resource(db(), $_POST['resource_id'] ?? '', $options), 3, []);
mmh_free_flash($ok ? 'success' : 'error', $message);
$tail = is_array($data) && !empty($data['resource_id']) ? '?resource=' . rawurlencode($data['resource_id']) . '#resource-form' : '';
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/free-learning' . $tail); exit;
