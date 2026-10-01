<?php
require __DIR__ . '/includes/bootstrap.php';

// QR reader. The dashboard posts the text it decoded and gets a JSON preview of every product in it back (or a 422 with the reason).
// The verify popup then posts that text again with the fields the user filled in (apply, as item[position][field]) and lands back on the dashboard.
if (isset($_POST['apply'])) {
    try {
        redirect('index.php', $inventory->applyScan($_POST['text'] ?? '', (array) ($_POST['item'] ?? [])));
    } catch (ValidationException $e) {
        redirect('index.php', $e->getMessage(), 'error');
    }
}
try {
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode($inventory->scan($_POST['text'] ?? '')));
} catch (ValidationException $e) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    exit($e->getMessage());
}
