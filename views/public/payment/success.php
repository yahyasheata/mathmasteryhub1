<?php
// Fawaterak redirects are informational only. Enrollment is activated by the
// authenticated webhook after its signature and invoice mapping are verified.
require_once '../../connection/config.php';
http_response_code(200);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Payment received</title></head>
<body>
<main style="max-width:42rem;margin:4rem auto;font-family:system-ui,sans-serif;text-align:center">
    <h1>Payment received</h1>
    <p>Your payment is being verified. We will add the course after the provider confirms the transaction.</p>
    <a href="/user/my-courses">Go to My Courses</a>
</main>
</body></html>
