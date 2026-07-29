<?php
require_once 'connection/config.php';
require_once 'inc/FreeResources.php';
require_once 'inc/LearningEvents.php';

$conn = db();
mmh_free_ensure_schema($conn);
mmh_free_open_resource($conn, $resourceId ?? '');
?>
