<?php
require __DIR__ . '/includes/bootstrap.php';
Auth::isSuperAdmin() || redirect('index.php', 'Only the super admin can use the Manager.', 'error');   // Load and Delete replace or erase the whole database

// Save-file actions from the Manager popup in the sidebar. Save is a download; Load and Delete are POSTs that end on the dashboard.
if (isset($_GET['save'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="inventory-' . date('Y-m-d') . '.scinvent"');
    exit($inventory->export());
}

if (isset($_POST['delete'])) {
    $inventory->wipe();
    redirect('index.php', 'Inventory deleted.');
}

if (isset($_POST['load'])) {
    try {
        ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK || throw new ValidationException('The file could not be uploaded.');
        $inventory->import(file_get_contents($_FILES['file']['tmp_name']));
        redirect('index.php', 'Inventory loaded from file.');
    } catch (ValidationException $e) {
        redirect('index.php', $e->getMessage(), 'error');
    }
}

redirect('index.php');
