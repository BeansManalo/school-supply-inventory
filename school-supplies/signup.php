<?php
define('LOGIN_PAGE', true);   // works without being signed in, like login.php
require __DIR__ . '/includes/bootstrap.php';

isset($_SESSION['user']) && redirect('index.php');

$error = null;
if (is_string($_POST['username'] ?? null) && is_string($_POST['password'] ?? null) && is_string($_POST['confirm'] ?? null)) {
    $error = Auth::register(trim($_POST['username']), $_POST['password'], $_POST['confirm']);   // a new account is a viewer (see core/Auth.php)
    $error || redirect('login.php?new');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign up - School Supplies</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<main class="form login">
    <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/></svg></span>
    <h1>School Supplies</h1>
    <p class="hint">Create an account. You start as a viewer; the super admin can give you more.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <label>Username <span class="hint">(letters, numbers, . - _)</span>
            <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.\-]+" autocomplete="username" autofocus required>
        </label>
        <label>Password <span class="hint">(at least 8 characters)</span>
            <input type="password" name="password" minlength="8" autocomplete="new-password" required>
        </label>
        <label>Repeat password
            <input type="password" name="confirm" minlength="8" autocomplete="new-password" required>
        </label>
        <button class="btn">Sign up</button>
    </form>
    <p class="hint">Already have an account? <a href="login.php">Sign in</a></p>
</main>
</body>
</html>
