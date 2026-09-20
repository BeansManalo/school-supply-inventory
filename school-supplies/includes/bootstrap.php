<?php
// Loads core/ClassName.php automatically, so new classes need no registration.
spl_autoload_register(fn($class) => require __DIR__ . "/../core/$class.php");
session_start();

// One shared Inventory for the whole session (the equivalent of a single in-memory store).
$_SESSION['inventory'] ??= new Inventory();
$inventory = $_SESSION['inventory'];

/** Escape a value for HTML output. */
function e(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Redirect after a POST; $message is shown once on the next page. */
function redirect(string $to, ?string $message = null) {
    if ($message) {
        $_SESSION['flash'] = $message;
    }
    header("Location: $to");
    exit;
}
