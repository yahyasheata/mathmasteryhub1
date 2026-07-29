<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php'; mmh_free_require_admin_csrf();
mmh_free_run_schema_maintenance(db()); mmh_free_flash('success', 'Free Learning schema maintenance completed.'); header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/free-learning'); exit;
