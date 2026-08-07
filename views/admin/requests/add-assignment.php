<?php
/* Compatibility endpoint. Assignment definitions are created by the
 * classified_assignment Course Content element only. */
header('Content-Type: application/json; charset=utf-8');
http_response_code(409);
echo json_encode([
    'success' => false,
    'status' => 0,
    'message' => 'Create assignments from the Assignment element inside Course Content.',
]);
exit;
