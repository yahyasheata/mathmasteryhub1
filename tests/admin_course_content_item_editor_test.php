<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/views/admin/course-content.php');
$handler = file_get_contents($root . '/views/admin/requests/form-item.php');
$index = file_get_contents($root . '/index.php');

if (!is_string($page) || !str_contains($page, "type: 'GET', url: 'requests/item/form'")) {
    throw new RuntimeException('Course Content item editor does not use the read-only GET endpoint.');
}
if (str_contains((string) $page, "type: 'POST', url: 'requests/item/form'")) {
    throw new RuntimeException('Course Content item editor still uses the mutation POST request.');
}
if (!is_string($index)
    || !str_contains($index, "\$router->get('/requests/item/form'")
    || !str_contains($index, "\$router->post('/requests/item/form'")) {
    throw new RuntimeException('Course Content item editor routes are not explicitly registered.');
}
if (!is_string($handler)
    || !str_contains($handler, '$form_item_request_method')
    || !str_contains($handler, '$form_item_request_data = $form_item_request_method === \'GET\' ? $_GET : $_POST;')
    || !str_contains($handler, "'html' =>")) {
    throw new RuntimeException('Item editor handler does not preserve the read-only GET/legacy response contract.');
}

echo "Admin Course Content item-editor regression checks passed.\n";
