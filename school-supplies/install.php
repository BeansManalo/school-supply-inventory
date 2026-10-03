<?php
// One-time setup. Start MySQL in the XAMPP Control Panel, then in this folder run:   C:\xampp\php\php.exe install.php
// Add the port as an argument if MySQL is not on 3306. Safe to run again: the data stays, the two database passwords are renewed.
PHP_SAPI === 'cli' || exit('Run this from a terminal, not the browser.');
set_exception_handler(fn(Throwable $ex) => exit("Setup stopped: {$ex->getMessage()}\nFix that (README, Troubleshooting) and run install.php again; it is safe to repeat.\n"));

$port = (int) ($argv[1] ?? 3306);
$dsn = "mysql:host=127.0.0.1;port=$port;charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {   // XAMPP ships root without a password; refuse to build on that
    new PDO($dsn, 'root', '', $options);
    exit("MariaDB's root account has no password. Set one first (README, setup step 4), then run this again.\n");
} catch (PDOException) {
}

echo 'MariaDB root password: ';
$rootPassword = rtrim((string) fgets(STDIN), "\r\n");
try {
    $root = new PDO($dsn, 'root', $rootPassword, $options);
} catch (PDOException $ex) {
    exit('Could not sign in as root: ' . $ex->getMessage() . "\n");
}

// This server may hold other programs' databases. Only the two below are ever touched (every name here starts with sc_), and a database
// that already has someone else's tables in it is left alone: the setup stops instead of adding to it.
$ours = ['sc_inventory' => ['categories', 'products', 'movements'], 'sc_accounts' => ['roles', 'users', 'requests', 'settings']];
foreach ($ours as $database => $tables) {
    $found = $root->query('SELECT LOWER(table_name) FROM information_schema.tables WHERE table_schema = ' . $root->quote($database))->fetchAll(PDO::FETCH_COLUMN);
    if ($found && !array_intersect($found, $tables)) {
        throw new RuntimeException("A database named $database already exists and holds tables that are not this app's (" . implode(', ', array_slice($found, 0, 5)) . '). Nothing was changed. Rename or remove that database first, or ask whoever owns it.');
    }
}

// One limited account per database, each with a new random password. They exist before the schema files run, because those grant to them.
$config = ['port' => $port];
foreach (['inventory' => 'sc_inventory', 'accounts' => 'sc_accounts'] as $key => $database) {
    $config[$key] = ['name' => $database, 'user' => "{$database}_app", 'pass' => bin2hex(random_bytes(24))];
    $root->exec("DROP USER IF EXISTS '{$config[$key]['user']}'@'127.0.0.1'");   // dropped first, so a re-run leaves exactly the privileges in the .sql files
    $root->exec("CREATE USER '{$config[$key]['user']}'@'127.0.0.1' IDENTIFIED BY " . $root->quote($config[$key]['pass']));
}

foreach (['inventory', 'accounts'] as $file) {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . "/database/$file.sql"));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $root->exec($statement);
    }
}

if (!$root->query('SELECT 1 FROM sc_accounts.users LIMIT 1')->fetch()) {
    $root->prepare('INSERT INTO sc_accounts.users (username, password_hash, role_id) SELECT ?, ?, id FROM sc_accounts.roles WHERE name = ?')
        ->execute(['admin', password_hash('1234', PASSWORD_DEFAULT), 'super_admin']);
    echo "Created the super admin account: admin / 1234. Replace that password (README).\n";
}

$old = is_file(__DIR__ . '/config.local.php') ? ((require __DIR__ . '/config.local.php')['push'] ?? []) : [];
$config['push'] = ['port' => $old['port'] ?? 8765, 'secret' => $old['secret'] ?? bin2hex(random_bytes(32)), 'php' => PHP_BINARY];   // see core/Push.php

file_put_contents(__DIR__ . '/config.local.php', "<?php\n// Database passwords, written by install.php. Never commit this file or serve it.\nreturn " . var_export($config, true) . ";\n");
@chmod(__DIR__ . '/config.local.php', 0600);
echo "Done. config.local.php is written; open the app and sign in.\n";
