<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php'; mmh_free_require_admin_csrf();
[$ok, $message] = mmh_free_restore_resource(db(), $_POST['resource_id'] ?? ''); mmh_free_flash($ok ? 'success' : 'error', $message); header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/free-learning?status=archived'); exit;
