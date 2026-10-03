<?php
// Loads core/ClassName.php automatically, so new classes need no registration.
spl_autoload_register(fn($class) => require __DIR__ . "/../core/$class.php");

// Nothing from PHP or the database (user names, table names, paths) ever reaches the page: it goes to the PHP error log instead.
// The messages of this app's own RuntimeExceptions (for example "config.local.php is missing") are written for the user and do show.
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $ex) {
    error_log((string) $ex);
    http_response_code(500);
    exit($ex instanceof RuntimeException && !$ex instanceof PDOException
        ? e($ex->getMessage())
        : 'The database is not available, or something went wrong. If MySQL is stopped, start it in the XAMPP Control Panel; otherwise see the PHP error log.');
});

// cookie_secure: over https the cookie is never sent in the clear (it stays off on plain http, or sign-in would not work there at all).
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);   // also holds the flash message and the signed-in account; its lock keeps one browser's requests from saving over each other

// Everything except login.php and signup.php (which define LOGIN_PAGE) needs a signed-in account (see core/Auth.php).
if (!defined('LOGIN_PAGE')) {
    empty($_SESSION['user']) || Auth::refresh();   // the role is read again on every request
    if (empty($_SESSION['user'])) {
        ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'navigate') === 'navigate' && redirect('login.php');   // a page: go sign in
        http_response_code(401);   // a fetch() from the page (QR, scan, report): app.js shows this text
        exit('Your session has ended. Reload the page and sign in again.');
    }
    defined('NO_INVENTORY') || $inventory = new Inventory();   // read from the database (see core/Inventory.php) on every request, except poll.php's
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
