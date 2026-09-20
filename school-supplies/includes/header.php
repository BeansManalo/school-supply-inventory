<?php
// Expects $title. Optional: $active (nav file to highlight), $error (message to show).
// To add a page to the menu, add one line to $nav.
$nav = [
    'index.php'    => 'Dashboard',
    'products.php' => 'Products',
    'stock.php'    => 'Stock In/Out',
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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<aside class="sidebar">
    <div class="brand">School Supplies</div>
    <nav>
        <?php foreach ($nav as $file => $label): ?>
            <a href="<?= $file ?>" class="<?= $file === $active ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
<main class="content">
    <h1><?= e($title) ?></h1>
    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="alert alert-ok"><?= e($_SESSION['flash']) ?></div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
