<?php
// Live updates: what changed for the signed-in account since the page was drawn (the script is in assets/js/app.js).
// bootstrap.php has already read the account again, so a banned, deleted or signed-out account gets the 401 the script acts on.
define('NO_INVENTORY', true);   // this is asked for every few seconds by every open page, and it needs no products
require __DIR__ . '/includes/bootstrap.php';
session_write_close();   // read-only from here, so this doesn't hold up the same person's other requests
Push::ensure();          // the push server (core/Push.php) is started here if it is not running

$super = Auth::isSuperAdmin();
$reply = ['role' => $_SESSION['user']['role']];   // the page reloads itself when this is no longer its role
($token = Push::token()) && $reply['push'] = $token;   // a fresh one, for the page's next WebSocket connection
if ($super) {
    $reply['pending'] = Requests::count();   // the number on the account button
}
foreach ($super ? ['mailbox', 'accounts'] : ['request'] as $name) {
    $have = $_GET['v'][$name] ?? null;   // the version of this panel the page shows, if it shows it
    if (is_string($have) && md5($html = Live::render($name)) !== $have) {
        $reply['panels'][$name] = Live::wrap($name, $html);
    }
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode($reply);
