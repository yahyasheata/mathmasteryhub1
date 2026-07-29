<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php';
mmh_free_require_admin_csrf();
[$ok, $message, $data] = array_pad(mmh_free_save_collection(db(), $_POST, $_FILES), 3, []);
mmh_free_flash($ok ? 'success' : 'error', $message);
$tail = is_array($data) && !empty($data['collection_id']) ? '?collection=' . rawurlencode($data['collection_id']) . '#collection-form' : '#collection-form';
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/free-learning' . $tail); exit;
