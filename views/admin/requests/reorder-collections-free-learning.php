<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php'; mmh_free_require_admin_csrf();
[$ok, $message] = mmh_free_reorder_collections(db(), $_POST['collection_ids'] ?? []); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>$ok,'message'=>$message]); exit;
