<?php
require_once 'connection/config.php';
require_once 'inc/LiveSessions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    mmh_live_response(false, 'Unauthorized request.', [], 403);
}

[$ok, $message] = mmh_live_delete_occurrence(db(), $_POST['occurrence_id'] ?? '');
mmh_live_response($ok, $message, [], $ok ? 200 : 422);

