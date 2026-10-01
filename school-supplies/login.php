<?php
define('LOGIN_PAGE', true);   // the one page that works without being signed in
require __DIR__ . '/includes/bootstrap.php';

if (isset($_POST['logout'])) {
    unset($_SESSION['user']);
    session_regenerate_id(true);
    redirect('login.php?out');
}
isset($_SESSION['user']) && redirect('index.php');

$notice = isset($_GET['out']) ? ['Signed out.', 'ok'] : null;
if (is_string($_POST['username'] ?? null) && is_string($_POST['password'] ?? null)) {
    if ($account = Auth::attempt(trim($_POST['username']), $_POST['password'])) {
        session_regenerate_id(true);   // a new session id on sign-in, so an id someone planted before it is useless
        $_SESSION['user'] = $account;
        redirect('index.php');
    }
    $notice = ['Wrong username or password. After 5 wrong passwords in a row an account is locked for 15 minutes.', 'error'];   // the same text whether or not the username exists or the account is locked
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in - School Supplies</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<main class="form login">
    <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/></svg></span>
    <h1>School Supplies</h1>
    <p class="hint">Sign in to continue</p>
    <?php if ($notice): ?><div class="alert alert-<?= $notice[1] ?>"><?= e($notice[0]) ?></div><?php endif; ?>
    <form method="post">
        <label>Username
            <input type="text" name="username" maxlength="50" autocomplete="username" autofocus required>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn">Sign in</button>
    </form>
</main>
</body>
</html>
