<?php
// Expects $title. Optional: $active (nav file to highlight), $error (message to show).
// To add a page to the menu, add one line to $nav.
$nav = [
    'index.php'    => 'Dashboard',
    'products.php' => 'Products',
    'reports.php'  => 'Reports',
];
$active ??= basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - School Supplies</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
<aside class="sidebar">
    <div class="brand">School Supplies</div>
    <nav>
        <?php foreach ($nav as $file => $label): ?>
            <a href="<?= $file ?>" class="<?= $file === $active ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if (Auth::isSuperAdmin()): ?><button type="button" id="open-manager">&#9881;&#xFE0E; Manager</button><?php endif; ?>
        <?php if (isset($_SESSION['user'])): ?>
            <button form="signout" name="logout" title="Signed in as <?= e($_SESSION['user']['username']) ?>">Sign out</button>
            <form id="signout" method="post" action="login.php"></form>
        <?php endif; ?>
    </nav>
</aside>
<?php if (Auth::isSuperAdmin()): ?>
<dialog id="manager-dialog" class="form manager">
    <h2>Manager</h2>
    <p class="hint">The inventory lives in the database, and a backup copy is refreshed on this computer every hour. Keep a copy, restore one, or start over.</p>
    <div class="mgr">
        <div>
            <strong>Save to file</strong>
            <p class="hint">Download the whole inventory as one file.</p>
        </div>
        <a class="btn" href="manager.php?save">Save</a>
    </div>
    <form class="mgr" method="post" action="manager.php" enctype="multipart/form-data"
          data-confirm="Load this file? It replaces the whole inventory in the database.">
        <div>
            <strong>Load from file</strong>
            <p class="hint">Replaces the whole inventory in the database with the file's content.</p>
            <input type="file" name="file" accept=".scinvent" required>
        </div>
        <button class="btn btn-secondary" name="load">Load</button>
    </form>
    <form class="mgr mgr-danger" method="post" action="manager.php"
          data-confirm="Delete the entire inventory and the backup copy? This cannot be undone.">
        <div>
            <strong>Delete inventory</strong>
            <p class="hint">Deletes all products, stock history and the backup copy. Save to file first if you might want it back.</p>
        </div>
        <button class="btn btn-danger" name="delete">Delete</button>
    </form>
    <form method="dialog" class="actions">
        <button class="btn btn-secondary">Close</button>
    </form>
</dialog>
<?php endif; ?>
<main class="content">
    <h1><?= e($title) ?></h1>
    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash'][1] ?>"><?= e($_SESSION['flash'][0]) ?></div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
