<?php
/**
 * The two database connections, 'inventory' and 'accounts'. Each uses its own limited MariaDB account (see database/*.sql)
 * with the password from config.local.php, which install.php writes. Only 127.0.0.1 is ever used.
 */
class Db {
    private static array $open = [];

    public static function get(string $which): PDO {
        return self::$open[$which] ??= self::connect($which);
    }

    private static function connect(string $which): PDO {
        is_file($file = __DIR__ . '/../config.local.php') || throw new RuntimeException('config.local.php is missing. Run install.php once (see README).');
        $config = require $file;
        $c = $config[$which];
        $pdo = new PDO("mysql:host=127.0.0.1;port={$config['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,   // real prepared statements: a value never becomes part of the SQL text
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // XAMPP's own default mode is not strict, so an over-long or out-of-range value would be cut or bent silently instead of refused.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }
}
