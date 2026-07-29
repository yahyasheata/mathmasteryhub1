<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php';
if (empty($_SESSION['admin'])) { http_response_code(403); exit; }
header('Content-Type: application/json; charset=utf-8'); echo json_encode(['results'=>mmh_free_resource_search(db(), $_GET['q'] ?? '', 12)]); exit;
