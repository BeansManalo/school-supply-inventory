<?php
/**
 * The account page panels that keep themselves current (Mailbox, Accounts, Request). Each is drawn inside a <div data-live data-v>,
 * where data-v is a hash of its HTML. The page asks poll.php every few seconds (see "Live updates" in assets/js/app.js); poll.php draws
 * the panel again and sends it only when its hash no longer matches the page's. A new live panel: a file includes/<name>_panel.php,
 * its name in poll.php's list for the roles that may see it, and Live::panel('<name>') where the page shows it.
 */
class Live {
    /** The panel's HTML, drawn fresh. Only ever called with a fixed name, never one from a request. */
    public static function render(string $name): string {
        ob_start();
        include __DIR__ . "/../includes/{$name}_panel.php";
        return trim(ob_get_clean());
    }

    /** The panel wrapped with its version. A panel that draws nothing (the Request panel for a top role) stays nothing. */
    public static function wrap(string $name, string $html): string {
        return $html === '' ? '' : '<div data-live="' . $name . '" data-v="' . md5($html) . '">' . $html . '</div>';
    }

    public static function panel(string $name): string {
        return self::wrap($name, self::render($name));
    }
}
