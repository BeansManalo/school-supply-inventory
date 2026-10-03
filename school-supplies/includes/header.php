<?php
// Expects $title. Optional: $active (nav file to highlight), $error (message to show).
// To add a page to the menu, add one line to $nav.
$nav = [
    'index.php'    => 'Dashboard',
    'products.php' => 'Products',
    'reports.php'  => 'Reports',
];
$active ??= basename($_SERVER['SCRIPT_NAME']);
$waiting = Auth::isSuperAdmin() ? Requests::count() : 0;   // access requests waiting: shown on the account button and in the tab title
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $waiting ? "($waiting) " : '' ?><?= e($title) ?> - School Supplies</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body data-role="<?= e($_SESSION['user']['role'] ?? '') ?>"<?= ($token = Push::token()) ? ' data-push="' . e($token) . '"' : '' ?>>
<aside class="sidebar">
    <div class="brand">School Supplies</div>
    <nav>
        <?php foreach ($nav as $file => $label): ?>
            <a href="<?= $file ?>" class="<?= $file === $active ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if (isset($_SESSION['user'])): ?>
            <a href="account.php<?= $waiting ? '#mailbox' : '' ?>" class="account <?= $active === 'account.php' ? 'active' : '' ?>" title="<?= $waiting ? $waiting . ' access request(s) waiting' : 'Your account' ?>">
                <span class="avatar"><?= e(mb_strtoupper(mb_substr($_SESSION['user']['username'], 0, 1))) ?></span>
                <span class="who"><b><?= e($_SESSION['user']['username']) ?></b><small><?= e(Auth::label($_SESSION['user']['role'])) ?></small></span>
                <?= Auth::isSuperAdmin() ? '<span class="count"' . ($waiting ? '' : ' hidden') . '>' . $waiting . '</span>' : '' ?>
            </a>
            <button form="signout" name="logout" title="Signed in as <?= e($_SESSION['user']['username']) ?>">Sign out</button>
            <form id="signout" method="post" action="login.php"></form>
        <?php endif; ?>
    </nav>
</aside>
<main class="content">
    <h1><?= e($title) ?></h1>
    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash'][1] ?>"><?= e($_SESSION['flash'][0]) ?></div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
