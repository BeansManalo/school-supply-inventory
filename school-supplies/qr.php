<?php
require __DIR__ . '/includes/bootstrap.php';
Auth::need('products');

// QR codes: takes the dashboard popup's product list (JSON) and returns the text to encode, or a 422 with the reason.
header('Content-Type: text/plain; charset=utf-8');
try {
    exit($inventory->qrPayload((array) json_decode($_POST['records'] ?? '', true)));
} catch (ValidationException $e) {
    http_response_code(422);
    exit($e->getMessage());
}
