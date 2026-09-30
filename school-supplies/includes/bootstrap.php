<?php
// Loads core/ClassName.php automatically, so new classes need no registration.
spl_autoload_register(fn($class) => require __DIR__ . "/../core/$class.php");
session_start();   // also holds the flash message; its lock keeps one browser's requests from saving over each other

// The Inventory loads from the local save files (see core/Store.php) on every request.
try {
    $inventory = new Inventory();
} catch (RuntimeException $ex) {
    http_response_code(500);
    exit(e($ex->getMessage()));
}

/** Escape a value for HTML output. */
function e(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Redirect after a POST; $message is shown once on the next page ($kind: 'ok' or 'error'). */
function redirect(string $to, ?string $message = null, string $kind = 'ok') {
    if ($message) {
        $_SESSION['flash'] = [$message, $kind];
    }
    header("Location: $to");
    exit;
}
