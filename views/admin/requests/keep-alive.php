<?php

// Start or resume the session
session_start();

// Perform a minimal action to touch the session
$_SESSION['keep_alive'] = time();

// Respond with a success message (or any other relevant response)
echo json_encode(['status' => 'success']);

?>