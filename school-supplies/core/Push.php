<?php
/**
 * The push channel: it tells open pages "something changed, ask poll.php now", so live updates arrive at once instead of within one
 * polling interval. ws-server.php (a small WebSocket server written in PHP, running in the background and listening on this PC only)
 * holds the browsers' connections, and Apache hands /ws to it (.htaccess). Only the word "changed" travels over it; what changed, and who
 * may see it, still come from poll.php. If the server is not running, pages poll as before. Settings: 'push' in config.local.php.
 */
class Push {
    private const TOKEN_HOURS = 24;

    private static function config(): ?array {
        static $config = false;
        return $config === false ? $config = (is_file($file = __DIR__ . '/../config.local.php') ? (require $file)['push'] ?? null : null) : $config;
    }

    public static function port(): ?int {
        return self::config()['port'] ?? null;
    }

    /** Signing and checking: the app and the server share the secret in config.local.php, nothing else. */
    private static function sign(string $text): string {
        return hash_hmac('sha256', $text, self::config()['secret']);
    }

    public static function verify(string $text, string $signature): bool {
        return self::config() !== null && hash_equals(self::sign($text), $signature);
    }

    /** What a page hands the socket to say who it is: account id, super admin or not, and an expiry, signed. */
    public static function token(): string {
        if (!self::config() || empty($_SESSION['user'])) {
            return '';
        }
        $text = $_SESSION['user']['id'] . '.' . (Auth::isSuperAdmin() ? 1 : 0) . '.' . (time() + self::TOKEN_HOURS * 3600);
        return $text . '.' . self::sign($text);
    }

    /** The topics a token entitles its connection to ('all', "u<id>", and 'admin' for the super admin), or null if it is forged or expired. */
    public static function topics(string $token): ?array {
        $part = explode('.', $token);
        if (count($part) !== 4 || !ctype_digit($part[0]) || !self::verify(implode('.', array_slice($part, 0, 3)), $part[3]) || (int) $part[2] < time()) {
            return null;
        }
        return array_merge(['all', "u{$part[0]}"], $part[1] === '1' ? ['admin'] : []);
    }

    /** Tells the connections on these topics to ask poll.php now. Best effort: it never slows or fails whatever called it. */
    public static function poke(array $topics): void {
        $list = implode(',', array_unique($topics));
        self::send("POKE $list " . self::sign($list));
    }

    public static function quit(): void {
        self::send('QUIT ' . self::sign('QUIT'));
    }

    private static function send(string $line): void {
        if (($port = self::port()) && ($socket = @stream_socket_client("tcp://127.0.0.1:$port", $code, $text, 0.3))) {
            fwrite($socket, "$line\n");
            fclose($socket);
        }
    }

    /** Does anything answer on the push port? */
    public static function running(): bool {
        if (($port = self::port()) && ($socket = @stream_socket_client("tcp://127.0.0.1:$port", $code, $text, 0.3))) {
            fclose($socket);
            return true;
        }
        return false;
    }

    /** Starts the server if nothing answers on its port, at most once in 30 seconds. The app works without it, so this fails quietly. */
    public static function ensure(): void {
        if (!($config = self::config()) || self::running() || @filemtime($stamp = sys_get_temp_dir() . "/school-supplies-push-{$config['port']}.try") > time() - 30) {
            return;
        }
        @touch($stamp);
        $script = realpath(__DIR__ . '/../ws-server.php');
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start \"\" /B \"{$config['php']}\" \"$script\"", 'r'));
        } else {
            exec(escapeshellarg($config['php']) . ' ' . escapeshellarg($script) . ' >/dev/null 2>&1 &');
        }
    }
}
