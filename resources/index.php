<?php
// This directory is an asset directory, not an application front controller.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found.';
