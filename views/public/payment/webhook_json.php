<?php
// Fawaterak webhook endpoint.  A webhook is accepted only after the provider
// signature is verified; activation is intentionally stopped until invoice
// metadata is persisted and can be matched to one user and one course.
header('Content-Type: application/json; charset=utf-8');
require_once 'connection/config.php';

$secret = trim((string) (getenv('FAWATERAK_VENDOR_KEY') ?: getenv('FAWATERAK_WEBHOOK_SECRET') ?: ''));
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['status' => 0, 'message' => 'Payment notifications are not configured.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['hashKey'])) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'Invalid data']);
    exit;
}

$hash = (string) $data['hashKey'];
if (isset($data['invoice_id'], $data['invoice_key'], $data['payment_method'])) {
    $queryParam = 'InvoiceId=' . (string) $data['invoice_id'] . '&InvoiceKey=' . (string) $data['invoice_key'] . '&PaymentMethod=' . (string) $data['payment_method'];
} elseif (isset($data['referenceId'], $data['paymentMethod'])) {
    $queryParam = 'referenceId=' . (string) $data['referenceId'] . '&PaymentMethod=' . (string) $data['paymentMethod'];
} else {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'Unknown webhook type']);
    exit;
}

$expectedHash = hash_hmac('sha256', $queryParam, $secret, false);
if (!hash_equals($expectedHash, $hash)) {
    http_response_code(403);
    echo json_encode(['status' => 0, 'message' => 'Invalid hash']);
    exit;
}

if (isset($data['invoice_status']) && strtolower((string) $data['invoice_status']) === 'paid') {
    // The current schema does not persist a trusted invoice-to-user/course
    // mapping. Never infer either identifier from the callback or activate a
    // default account. Configure a persisted invoice mapping before enabling.
    http_response_code(503);
    echo json_encode(['status' => 0, 'message' => 'Payment received but enrollment requires verified invoice metadata.']);
    exit;
}

echo json_encode(['status' => 1, 'message' => 'Webhook verified.']);
