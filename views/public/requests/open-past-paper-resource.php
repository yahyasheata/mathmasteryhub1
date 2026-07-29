<?php
require_once 'connection/config.php';
require_once 'inc/PastPapers.php';
require_once 'inc/LearningEvents.php';

$conn = db();
mmh_past_ensure_schema($conn);
mmh_past_open_resource($conn, $resourceId ?? '');
?>
