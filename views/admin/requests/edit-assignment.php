<?php
/* Compatibility endpoint. Assignment definitions are edited by the
 * classified_assignment Course Content element only. */
header('Content-Type: application/json; charset=utf-8');
http_response_code(409);
echo json_encode([
    'success' => false,
    'status' => 0,
    'message' => 'Open this assignment from Course Content to edit it.',
]);
exit;
