<?php
require __DIR__ . '/includes/bootstrap.php';

// Product QR codes: takes the dashboard popup's fields and returns the text to encode, or a 422 with the reason.
header('Content-Type: text/plain; charset=utf-8');
try {
    exit($inventory->qrPayload($_POST['id'] ?? '', $_POST['name'] ?? '', $_POST['category'] ?? '', $_POST['stock'] ?? ''));
} catch (ValidationException $e) {
    http_response_code(422);
    exit($e->getMessage());
}
