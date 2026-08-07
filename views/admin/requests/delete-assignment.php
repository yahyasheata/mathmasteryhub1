<?php
/* Compatibility endpoint. Archiving is owned by the Course Content item so
 * the assignment and its historical submissions stay together. */
header('Content-Type: application/json; charset=utf-8');
http_response_code(409);
echo json_encode([
    'success' => false,
    'status' => 0,
    'message' => 'Archive the Assignment element from Course Content.',
]);
exit;
