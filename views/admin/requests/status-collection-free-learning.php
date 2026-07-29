<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php'; mmh_free_require_admin_csrf();
[$ok,$message]=mmh_free_set_collection_status(db(),$_POST['collection_id'] ?? '',$_POST['status'] ?? 'draft'); mmh_free_flash($ok?'success':'error',$message); header('Location: '.rtrim((string)$baseUrl,'/').'/admin/free-learning#collection-form'); exit;
