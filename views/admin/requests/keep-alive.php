<?php
require_once __DIR__ . '/../../../inc/AdminSecurity.php';
mmh_admin_block_direct_internal_file();
mmh_admin_require_mutation();

// Start or resume the session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Perform a minimal action to touch the session
$_SESSION['keep_alive'] = time();

// Respond with a success message (or any other relevant response)
echo json_encode(['status' => 'success']);

?>
