<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/views/admin/course-content.php');
$handler = file_get_contents($root . '/views/admin/requests/items-item.php');
$index = file_get_contents($root . '/index.php');

if (!is_string($page) || !str_contains($page, "type: 'GET', url: 'requests/item/items'")) {
    throw new RuntimeException('Initial Course Content load does not use the read-only GET endpoint.');
}
if (!is_string($handler) || !str_contains($handler, '$items_request_method === \'GET\'')
    || !str_contains($handler, "'html' => " . '$manager_html')) {
    throw new RuntimeException('Course Content list handler does not preserve its GET response contract.');
}
if (!is_string($index) || !str_contains($index, "\$router->get('/requests/item/items'")
    || !str_contains($index, "\$router->post('/requests/item/items'")) {
    throw new RuntimeException('Course Content list routes are not explicitly registered.');
}
if (str_contains($page, "type: 'POST', url: 'requests/item/items'")) {
    throw new RuntimeException('Initial Course Content load still depends on the mutation POST path.');
}

echo "Admin Course Content initial-load regression checks passed.\n";
