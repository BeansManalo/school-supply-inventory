<?php
// One-click setup of the School Supplies app on XAMPP (Windows). Start it with Setup.bat; undo it with Revert.bat.
// Every step first checks whether it is already done, so running it again finishes a partial run or just verifies.
PHP_SAPI === 'cli' || exit('Run Setup.bat, not this file in a browser.');
set_time_limit(0);
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($n, $msg, $file, $line) {
    if (error_reporting() & $n) {
        throw new ErrorException($msg, 0, $n, $file, $line);
    }
    return true;
});

define('X', rtrim(str_replace('\\', '/', trim($argv[1] ?? '', " \t\"")), '/'));
define('SRC', str_replace('\\', '/', __DIR__) . '/school-supplies');
define('APP', X . '/htdocs/school-supplies');
define('BK', X . '/school-supplies-setup-backup');   // originals of everything changed, and the record Revert.bat reads
define('INI', X . '/mysql/bin/my.ini');
define('DATA', X . '/mysql/data');
define('HTTPD', X . '/apache/conf/httpd.conf');
define('PMA', X . '/phpMyAdmin/config.inc.php');
$m = [];            // the record saved in BK/manifest.json
$rootPw = null;     // MariaDB root password once known ('' = none yet)

function say(string $s): void { echo $s, PHP_EOL; }
function fail(string $s) { throw new RuntimeException($s); }
function b64(string $s): string { return base64_encode(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')); }
function tail(string $f, int $n = 8): string { return is_file($f) ? trim(implode('', array_slice(file($f), -$n))) : '(no log file found)'; }
function waitFor(callable $f, int $sec): bool { for ($i = 0; $i < $sec * 2; $i++) { if ($f()) return true; usleep(500000); } return false; }
function up(int $port, string $host = '127.0.0.1'): bool { if ($s = @fsockopen($host, $port, $e, $r, 1)) { fclose($s); return true; } return false; }
function lint(string $f): bool { return run([PHP_BINARY, '-l', $f])[0] === 0; }
function acct(PDO $db, string $u, string $h): string { return $db->quote($u) . '@' . $db->quote($h); }

/** Runs a program (no shell, so no quoting trouble); returns [exit code, stdout+stderr]. */
function run(array $cmd, array $env = [], ?string $in = null, ?string $cwd = null): array {
    $p = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['redirect', 1]], $pipes, $cwd, $env ? $env + getenv() : null) ?: fail("Could not run {$cmd[0]}");
    fwrite($pipes[0], $in ?? '');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), $out];
}

/** Starts a program in the background with no window, so it keeps running after this window is closed (like XAMPP's own panel does). */
function launch(string $exe, array $args): int {
    $q = fn($s) => "'" . str_replace("'", "''", $s) . "'";
    $a = $args ? ' -ArgumentList ' . implode(',', array_map(fn($s) => $q("\"$s\""), $args)) : '';
    [$c, $o] = run(['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand',
        b64("(Start-Process -FilePath {$q($exe)}$a -WorkingDirectory {$q(X)} -WindowStyle Hidden -PassThru).Id")]);
    $c === 0 || fail("Could not start $exe: " . trim($o));
    return (int) trim($o);
}

function ask(string $q, bool $hidden = false): string {
    echo $q;
    if ($hidden && PHP_OS_FAMILY === 'Windows') {   // typed characters stay invisible; falls back to a visible prompt if PowerShell objects
        exec('powershell -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . b64('$p = Read-Host -AsSecureString; [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes([Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))))'), $out, $code);
        if ($code === 0 && $out) {
            echo PHP_EOL;
            return base64_decode(trim(end($out)));
        }
    }
    ($l = fgets(STDIN)) === false && fail('The input ended before every question was answered.');
    return rtrim($l, "\r\n");
}

function askNew(string $what, array $not = []): string {
    say($what);
    while (true) {
        $p = ask('  New password (at least 8 characters): ', true);
        if (strlen($p) < 8) { say('  Too short.'); continue; }
        if (in_array($p, $not, true)) { say('  Use one that is different from the root password.'); continue; }
        if (ask('  Type it again: ', true) === $p) return $p;
        say('  The two did not match. Try again.');
    }
}

// ---------------------------------------------------------------- the record and the backups

function save(): void {
    global $m;
    is_dir(BK) || mkdir(BK, 0777, true);
    file_put_contents(BK . '/manifest.tmp', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename(BK . '/manifest.tmp', BK . '/manifest.json');   // swapped in whole, so a crash never leaves half a record
}

/** Keeps the first (original) copy of a file; later runs never overwrite it. */
function backup(string $file, string $name): void {
    $to = BK . "/$name";
    if (!is_file($to)) {
        is_dir(dirname($to)) || mkdir(dirname($to), 0777, true);
        copy($file, $to) || fail("Could not back up $file");
    }
}

/** Call before changing a file inside the app folder: remembers whether it is new or replaced (and saves the original). */
function track(string $rel): void {
    global $m;
    if (in_array($rel, $m['created'] ?? [], true) || in_array($rel, $m['replaced'] ?? [], true)) return;
    if (is_file(APP . "/$rel")) {
        backup(APP . "/$rel", "app/$rel");
        $m['replaced'][] = $rel;
    } else {
        $m['created'][] = $rel;
    }
    save();
}

// ---------------------------------------------------------------- checks before anything changes

function preflight(): void {
    $miss = [];
    foreach (['mysql/bin/mysqld.exe', 'mysql/bin/mysqldump.exe', 'mysql/bin/mysqlcheck.exe', 'mysql/bin/my.ini', 'apache/bin/httpd.exe', 'apache/conf/httpd.conf', 'htdocs'] as $f) {
        file_exists(X . "/$f") || $miss[] = "XAMPP is missing $f (looked in " . X . '). Install XAMPP from https://www.apachefriends.org first.';
    }
    foreach (['install.php', 'database/inventory.sql', 'database/accounts.sql'] as $f) {
        is_file(SRC . "/$f") || $miss[] = "The app file school-supplies/$f is missing next to setup.php. Extract the whole zip first.";
    }
    foreach (['pdo_mysql', 'openssl', 'mbstring', 'zlib', 'iconv'] as $e) {
        extension_loaded($e) || $miss[] = "PHP extension '$e' is switched off. In " . (php_ini_loaded_file() ?: 'php.ini') . " remove the ';' in front of extension=$e.";
    }
    ini_get('allow_url_fopen') || $miss[] = 'allow_url_fopen is Off in ' . (php_ini_loaded_file() ?: 'php.ini') . '; set it to On.';
    version_compare(PHP_VERSION, '8.0', '>=') || $miss[] = 'PHP 8.0 or newer is needed; this XAMPP has ' . PHP_VERSION . '.';
    try {
        run(['powershell', '-NoProfile', '-Command', 'exit 0'])[0] === 0 || throw new RuntimeException();
    } catch (Throwable) {
        $miss[] = 'Windows PowerShell was not found (it is part of Windows 7 SP1 and newer).';
    }
    if ($miss) {
        say('Setup cannot start yet. Fix these first:');
        foreach ($miss as $s) say("  - $s");
        exit(1);
    }
}

// ---------------------------------------------------------------- step 1: the app files

function syncApp() {
    if (strcasecmp((string) realpath(SRC), (string) realpath(APP)) === 0) {
        return say('[ok] The app already runs from htdocs/school-supplies.');
    }
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SRC, FilesystemIterator::SKIP_DOTS)) as $f) {
        $from = $f->getPathname();
        $rel = str_replace('\\', '/', substr($from, strlen(SRC) + 1));
        $to = APP . "/$rel";
        if ($rel === 'config.local.php' || (is_file($to) && md5_file($to) === md5_file($from))) continue;
        track($rel);
        is_dir(dirname($to)) || mkdir(dirname($to), 0777, true);
        copy($from, $to) || fail("Could not copy $rel into htdocs");
        $n++;
    }
    say($n ? "[ok] Copied $n app file(s) into htdocs/school-supplies." : '[ok] The app files in htdocs are already up to date.');
}

// ---------------------------------------------------------------- step 2: my.ini (README step 2)

/** Makes sure [mysqld] has bind-address=127.0.0.1 (and lets MariaDB clear stale Aria logs itself after a crash). Returns true if bind-address had to be changed, which needs a MySQL restart. */
function fixIni(): bool {
    $t = file_get_contents(INI);
    $nl = str_contains($t, "\r\n") ? "\r\n" : "\n";
    $lines = preg_split('/\r?\n/', $t);
    $sec = '';
    $head = null;
    $seen = $edit = false;
    foreach ($lines as $i => $l) {
        if (preg_match('/^\s*\[([^\]]+)\]/', $l, $mm)) {
            $sec = strtolower(trim($mm[1]));
            if ($sec === 'mysqld' && $head === null) $head = $i;
        } elseif ($sec === 'mysqld' && preg_match('/^\s*bind[-_]address\s*=\s*(\S*)/i', $l, $mm)) {
            $seen = true;
            if ($mm[1] !== '127.0.0.1') {
                $lines[$i] = 'bind-address=127.0.0.1';
                $edit = true;
            }
        }
    }
    $head === null && fail('my.ini has no [mysqld] section, so it looks damaged. Restore it (reinstall XAMPP, or copy a good my.ini into ' . dirname(INI) . ') and run Setup.bat again.');
    if (!$seen) {
        array_splice($lines, $head + 1, 0, 'bind-address=127.0.0.1');
        $edit = true;
    }
    $restart = $edit;
    if (!preg_match('/^\s*aria[-_]force[-_]start[-_]after[-_]recovery[-_]failures\s*=/mi', $t)) {   // the usual "MySQL shutdown unexpectedly" after Windows went down without Stop
        array_splice($lines, $head + 1, 0, 'aria_force_start_after_recovery_failures=1');
        $edit = true;
        say('[ok] my.ini: MariaDB now clears stale Aria log files itself after a crash.');
    }
    if ($edit) {
        backup(INI, 'files/my.ini');
        file_put_contents(INI, implode($nl, $lines));
    }
    return $restart;
}

function iniPort(): int {
    $sec = '';
    foreach (file(INI) as $l) {
        if (preg_match('/^\s*\[([^\]]+)\]/', $l, $mm)) $sec = strtolower(trim($mm[1]));
        elseif ($sec === 'mysqld' && preg_match('/^\s*port\s*=\s*(\d+)/i', $l, $mm)) return (int) $mm[1];
    }
    return 3306;
}

// ---------------------------------------------------------------- step 5: phpMyAdmin login (README step 5)

function phpSet(string $t, string $lhs, string $rhs, string $nl): string {
    $re = '/^([ \t]*' . preg_quote($lhs, '/') . '\s*=\s*)[^;\r\n]*;/m';
    if (preg_match($re, $t)) return preg_replace_callback($re, fn($mm) => "{$mm[1]}$rhs;", $t);
    $add = "$lhs = $rhs;$nl";
    return preg_match('/\?>\s*$/', $t) ? preg_replace_callback('/\?>\s*$/', fn() => $add . '?>' . $nl, $t) : rtrim($t) . $nl . $add;
}

function fixPma() {
    if (!is_file(PMA)) return say('[--] phpMyAdmin is not installed; skipped (it is optional).');
    $t = file_get_contents(PMA);
    $base = $t;
    if (!lint(PMA)) {   // damaged: start again from phpMyAdmin's own sample file
        $sample = dirname(PMA) . '/config.sample.inc.php';
        is_file($sample) && lint($sample) || fail('phpMyAdmin\'s config.inc.php has a syntax error and there is no good sample to rebuild it from. Restore it by hand (README step 5), then run Setup.bat again.');
        $base = file_get_contents($sample);
        is_dir(BK . '/files') || mkdir(BK . '/files', 0777, true);
        copy(PMA, BK . '/files/config.inc.php.damaged-' . date('Ymd-His'));
        say('[!!] phpMyAdmin\'s config.inc.php was damaged; rebuilding it from config.sample.inc.php (the damaged copy is kept in the backup folder).');
    }
    $nl = str_contains($base, "\r\n") ? "\r\n" : "\n";
    $new = $base;
    if (!preg_match('/\$cfg\[\'blowfish_secret\'\]\s*=\s*\'[^\']{32,}\'/', $new)) {
        $new = phpSet($new, '$cfg[\'blowfish_secret\']', "'" . bin2hex(random_bytes(16)) . "'", $nl);
    }
    $new = phpSet($new, '$cfg[\'Servers\'][$i][\'auth_type\']', "'cookie'", $nl);
    $new = phpSet($new, '$cfg[\'Servers\'][$i][\'AllowNoPassword\']', 'false', $nl);
    if ($new === $t) return say('[ok] phpMyAdmin already asks for a login.');
    backup(PMA, 'files/config.inc.php');
    file_put_contents(PMA, $new);
    if (!lint(PMA)) {
        copy(BK . '/files/config.inc.php', PMA);
        fail('Editing phpMyAdmin\'s config.inc.php produced a syntax error, so the original was put back. Make the three changes by hand (README step 5).');
    }
    say('[ok] phpMyAdmin now asks for a login.');
}

// ---------------------------------------------------------------- MySQL

/** True once MariaDB answers connections. Its port opens a moment before that, so a server that then dies silently (the usual "MySQL shutdown unexpectedly") has already looked "up". */
function ready(): bool {
    if (!($s = @fsockopen('127.0.0.1', PORT, $e, $r, 1))) return false;
    stream_set_timeout($s, 1);
    $hello = fread($s, 1);   // a running MariaDB speaks first
    fclose($s);
    return $hello !== false && $hello !== '';
}

/** XAMPP's own cure for a server that dies while starting: its clean copy of the data folder (mysql/backup) replaces mysql/data and the old folder stays next to it. Refuses while the folder holds databases of the person's own, which the clean copy would hide. */
function resetData(): void {
    $src = X . '/mysql/backup';
    is_dir($src) || fail("MySQL will not start and XAMPP's clean copy of the data folder ($src) is missing, so Setup cannot repair it. Reinstall XAMPP, or restore that folder.");
    $mine = array_udiff(array_map('basename', glob(DATA . '/*', GLOB_ONLYDIR) ?: []), ['mysql', 'performance_schema', 'phpmyadmin', 'test'], 'strcasecmp');
    $mine && fail('MySQL will not start, and ' . DATA . ' holds databases of yours (' . implode(', ', $mine) . '), so Setup will not replace it. By hand, in ' . X . '/mysql: rename data to data_old, copy the backup folder to a new data folder, copy your database folders and data_old/ibdata1 into it, then run Setup.bat again.');
    $old = DATA . '-old-' . date('Ymd-His');
    rename(DATA, $old) || fail('Could not rename ' . DATA . '. Is mysqld.exe still running? End it in Task Manager, then run Setup.bat again.');
    mkdir(DATA);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
        $to = DATA . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
        if ($f->isDir()) {
            @mkdir($to, 0777, true);
        } else {
            copy($f->getPathname(), $to) || fail("Could not copy {$f->getFilename()} into the new data folder. The old folder is untouched at $old.");
        }
    }
    say("[!!] MySQL kept stopping right after it started. XAMPP's clean data folder is now in place; the old one is kept at $old.");
}

function startMysql(): void {
    $done = [];
    for ($try = 0; $try < 3; $try++) {
        say('[..] Starting MySQL (up to a minute)...');
        $pid = launch(X . '/mysql/bin/mysqld.exe', ['--defaults-file=' . INI, '--standalone']);
        if (waitFor('ready', 60)) return;
        $log = tail(DATA . '/mysql_error.log', 25);
        run(['taskkill', '/F', '/PID', (string) $pid]);   // a copy that never answered still holds the data files and would make the next one die
        if (!isset($done['aria']) && preg_match('/aria/i', $log)) {   // the usual "MySQL shutdown unexpectedly" after a power cut: stale Aria log files
            $done['aria'] = 1;
            @mkdir(BK . '/files/aria', 0777, true);
            foreach (glob(DATA . '/aria_log*') as $f) rename($f, BK . '/files/aria/' . basename($f));
            say('[!!] MySQL stumbled over its Aria log files; moved them to the backup folder and trying once more.');
            continue;
        }
        if (!isset($done['data']) && !preg_match('/bind on tcp|already in use/i', $log)) {   // not the port: the server itself dies, often with no error at all
            $done['data'] = 1;
            resetData();
            continue;
        }
        break;
    }
    fail("MySQL did not start. The end of its log says:\n$log\nIf that mentions the port ('Address already in use'), another program is using port " . PORT . '. If the log shows no error, run "' . X . '/mysql/bin/mysqld.exe" --defaults-file="' . INI . '" --console in a Command Prompt to see why, and check your antivirus.');
}

function denied(PDOException $e): bool { return str_contains($e->getMessage(), '1045'); }

function pdo(string $user, string $pass, string $db = '', string $host = '127.0.0.1'): PDO {
    return new PDO("mysql:host=$host;port=" . PORT . ($db ? ";dbname=$db" : '') . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function pdoRetry(string $user, string $pass): PDO {   // MySQL needs a moment after its port opens
    for ($i = 0;; $i++) {
        try {
            return pdo($user, $pass);
        } catch (PDOException $e) {
            if (denied($e) || $i > 20) throw $e;
            sleep(1);
        }
    }
}

/** A root connection: blank password first, otherwise asks (3 tries). */
function root(): PDO {
    global $rootPw;
    if ($rootPw !== null) return pdoRetry('root', $rootPw);
    try {
        $db = pdoRetry('root', '');
        $rootPw = '';
        return $db;
    } catch (PDOException $e) {
        denied($e) || throw $e;
    }
    for ($i = 0; $i < 3; $i++) {
        $p = ask('MariaDB root password: ', true);
        try {
            $db = pdoRetry('root', $p);
            $rootPw = $p;
            return $db;
        } catch (PDOException $e) {
            denied($e) || throw $e;
            say('[!!] That password was refused.');
        }
    }
    fail('Could not sign in as root. If the root password is lost it has to be reset by hand: https://mariadb.com/kb/en/reset-root-password/');
}

function norm(string $p): string { return rtrim(str_replace('\\', '/', realpath($p) ?: $p), '/'); }

/** Port in use by some other MySQL (a second install, WAMP...)? Then changing "root" here would hit the wrong server. */
function checkDatadir(PDO $db): void {
    $dd = $db->query('SELECT @@datadir')->fetchColumn();
    strcasecmp(norm($dd), norm(DATA)) === 0 || fail("Port " . PORT . " belongs to a different MySQL server (its data is in $dd), not XAMPP's. Stop that one, or give XAMPP another port in my.ini, then run Setup.bat again.");
}

/** True if MySQL also answers on this PC's network address (README step 3 check). */
function exposed(): bool {
    foreach (gethostbynamel(gethostname()) ?: [] as $ip) {
        if (strncmp($ip, '127.', 4) !== 0 && up(PORT, $ip)) return true;
    }
    return false;
}

// ---------------------------------------------------------------- step 4: root password (README step 4)

function hardenRoot(PDO $db): void {
    global $m, $rootPw;
    $rows = $db->query("SELECT user, host FROM mysql.user WHERE user IN ('root', '')")->fetchAll(PDO::FETCH_NUM);
    if (!isset($m['users'])) {   // the originals, written down before the first change, for Revert.bat
        $m['users'] = [];
        foreach ($rows as [$u, $h]) {
            $a = acct($db, $u, $h);
            try {
                $m['users'][] = ['user' => $u, 'host' => $h, 'create' => $db->query("SHOW CREATE USER $a")->fetchColumn(),
                    'grants' => $u === '' ? $db->query("SHOW GRANTS FOR $a")->fetchAll(PDO::FETCH_COLUMN) : []];
            } catch (PDOException) {
                $m['users'][] = ['user' => $u, 'host' => $h, 'create' => null, 'grants' => []];
            }
        }
        save();
    }
    if ($rootPw === '') {
        say('MariaDB (the database) is open to anyone on this PC until "root" gets a password.');
        $rootPw = askNew('Choose the MariaDB root password. You only need it for Setup.bat, Revert.bat and phpMyAdmin, never for the app itself.');
    }
    foreach ($rows as [$u, $h]) {
        $a = acct($db, $u, $h);
        $db->exec($u === '' ? "DROP USER $a" : "ALTER USER $a IDENTIFIED BY " . $db->quote($rootPw));
    }
    foreach (['127.0.0.1', 'localhost'] as $h) {
        try {
            pdo('root', '', '', $h);
        } catch (PDOException) {
            continue;
        }
        fail("root can still sign in without a password through $h. Run Setup.bat again; if it persists, set the root password by hand (README step 4).");
    }
    say('[ok] MariaDB root now has a password, and the anonymous accounts are gone.');
}

// ---------------------------------------------------------------- step 6: databases (README step 6, done by the app's own install.php)

/** Are the databases, both app accounts, a sign-in account and the roles of this version all in place and working? (A copy set up by an older version fails the roles check, so install.php runs again and brings them in.) */
function installed(): bool {
    try {
        $c = require APP . '/config.local.php';
        if ($c['port'] != PORT || !isset($c['push'])) return false;
        pdo($c['inventory']['user'], $c['inventory']['pass'], $c['inventory']['name'])->query('SELECT 1 FROM products LIMIT 1');
        $accounts = pdo($c['accounts']['user'], $c['accounts']['pass'], $c['accounts']['name']);
        $accounts->query('SELECT banned, requests_muted FROM users LIMIT 0');   // a missing column or table throws, so install.php runs again
        $accounts->query('SELECT 1 FROM requests, settings LIMIT 0');
        return $accounts->query('SELECT 1 FROM users LIMIT 1')->fetch() && $accounts->query("SELECT 1 FROM roles WHERE name = 'viewer'")->fetch();
    } catch (Throwable) {
        return false;
    }
}

function installDbs(PDO $db) {
    global $m, $rootPw;
    if (!isset($m['before'])) {   // what existed already, so Revert.bat only removes what Setup made
        $m['before'] = [
            'db' => $db->query("SHOW DATABASES WHERE `Database` IN ('sc_inventory', 'sc_accounts')")->fetchAll(PDO::FETCH_COLUMN),
            'user' => $db->query("SELECT user FROM mysql.user WHERE user IN ('sc_inventory_app', 'sc_accounts_app')")->fetchAll(PDO::FETCH_COLUMN),
        ];
        save();
    }
    track('config.local.php');
    for ($try = 0; $try < 3; $try++) {
        [, $out] = run([PHP_BINARY, 'install.php', (string) PORT], [], "$rootPw\n", APP);
        if (str_contains($out, 'Done.')) return say('[ok] Databases, the two app accounts and config.local.php are in place.');
        if (!str_contains($out, 'crashed')) fail(trim($out));
        say('[!!] A MySQL system table is marked as crashed (an unclean shutdown). Repairing it.');
        preg_match("~Table '(?:\./)?(?:(\w+)/)?(\w+)' is marked as crashed~", $out, $t) || fail(trim($out));
        if ($try === 0) {   // README: mysqlcheck first, on that one database only (other programs' databases are not touched)
            run([X . '/mysql/bin/mysqlcheck.exe', '--host=127.0.0.1', '--port=' . PORT, '-uroot', '--auto-repair', '--databases', $t[1] ?: 'mysql'], ['MYSQL_PWD' => $rootPw]);
        } else {            // README: then the table tool, with MySQL stopped
            $base = DATA . '/' . ($t[1] ?: 'mysql') . '/' . $t[2];
            root()->exec('SHUTDOWN');
            waitFor(fn() => !up(PORT), 60);
            run([X . '/mysql/bin/' . (is_file("$base.MAI") ? 'aria_chk.exe' : 'myisamchk.exe'), '-r', $base]);
            startMysql();
        }
    }
    fail("The crashed table could not be repaired automatically.\n" . trim($out) . "\nSee README, Troubleshooting.");
}

// ---------------------------------------------------------------- step 8: admin password (README step 8)

function adminPassword() {
    global $rootPw;
    $c = require APP . '/config.local.php';
    $hash = pdo($c['accounts']['user'], $c['accounts']['pass'], $c['accounts']['name'])->query("SELECT password_hash FROM users WHERE username = 'admin'")->fetchColumn();
    if (!$hash || !password_verify('1234', $hash)) return say('[ok] The admin sign-in password is already your own.');
    $db = root();
    $p = askNew('The app\'s "admin" account still has the placeholder password 1234. Choose the password you will sign in with:', [$rootPw]);
    $db->prepare("UPDATE sc_accounts.users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE username = 'admin'")->execute([password_hash($p, PASSWORD_DEFAULT)]);
    say('[ok] The admin password is changed.');
}

// ---------------------------------------------------------------- step 7: Apache (README step 7)

function http(string $url): array {   // [status code or 0, Server header]
    $h = @get_headers($url, false, stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 0]]));
    $srv = '';
    foreach ($h ?: [] as $l) {
        if (stripos($l, 'Server:') === 0) $srv = trim(substr($l, 7));
    }
    return [$h && preg_match('/ (\d{3})/', $h[0], $mm) ? (int) $mm[1] : 0, $srv];
}

function stopApache(int $port): void {
    run(['taskkill', '/F', '/IM', 'httpd.exe']);
    waitFor(fn() => !up($port), 15);
}

/** The .htaccess only works where httpd.conf says AllowOverride All for htdocs. Fixes that if needed; true if it changed the file. */
function fixOverride(): bool {
    $t = file_get_contents(HTTPD);
    $new = preg_replace_callback('~<Directory\s+"?[^">\r\n]*/htdocs/?"?\s*>.*?</Directory>~is', function ($b) {
        return preg_match('/^\s*AllowOverride\b/mi', $b[0])
            ? preg_replace('/^(\s*AllowOverride\s+)[^\r\n]*/mi', '${1}All', $b[0])
            : preg_replace('~</Directory>~i', "    AllowOverride All\n</Directory>", $b[0]);
    }, $t, 1);
    if ($new === null || $new === $t) return false;
    backup(HTTPD, 'files/httpd.conf');
    file_put_contents(HTTPD, $new);
    if (run([X . '/apache/bin/httpd.exe', '-t', '-f', HTTPD], [], null, X)[0] !== 0) {
        copy(BK . '/files/httpd.conf', HTTPD);
        return false;
    }
    return true;
}

/** Setup.bat runs as administrator, so MySQL and Apache do too, and an ordinary XAMPP panel can then neither see nor stop them ("Unable to open process") nor save xampp-control.ini. This makes Windows open the panel as administrator (the "Run as administrator" box under Properties > Compatibility). */
function panelFlag(string $verb): void {
    run(['reg', $verb, 'HKCU\Software\Microsoft\Windows NT\CurrentVersion\AppCompatFlags\Layers', '/v', str_replace('/', '\\', X) . '\xampp-control.exe', ...($verb === 'add' ? ['/t', 'REG_SZ', '/d', '~ RUNASADMIN'] : []), '/f']);
}

/** Runs PowerShell commands in an administrator window (Windows asks once) and returns their exit code. */
function admin(string $cmds): int {
    return run(['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', b64(
        '$p = Start-Process powershell -Verb RunAs -Wait -PassThru -WindowStyle Hidden -ArgumentList "-NoProfile","-ExecutionPolicy","Bypass","-EncodedCommand","' . b64($cmds) . '"; exit $p.ExitCode')])[0];
}

function apachePort(): int {
    preg_match('/^\s*Listen\s+(?:\S*:)?(\d+)\s*$/mi', file_get_contents(HTTPD), $mm);
    return (int) ($mm[1] ?? 80);
}

/** Windows' own web driver (HTTP.sys, shown as "PID 4 / System") holds the port on behalf of a service such as IIS. Stops those services and sets them to Disabled so they stay off after a restart; Revert.bat sets them back. */
function freePort(int $port): void {
    global $m;
    [, $o] = run(['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', b64(<<<'PS'
Get-CimInstance Win32_Service | Where-Object { $_.State -eq 'Running' -and ($_.Name -in 'W3SVC','MsDepSvc','ReportServer','SQLServerReportingServices','PowerBIReportServer' -or $_.Name -like 'ReportServer$*') } | ForEach-Object { $_.Name + '|' + $_.StartMode }
PS)]);
    preg_match_all('/^([\w$]+)\|(\w+)\s*$/m', $o, $svc, PREG_SET_ORDER);
    $svc || fail("Port $port is held by Windows' web driver, but none of the usual services (IIS, SQL Server Reporting Services...) explain it. In an administrator Command Prompt run: netsh http show servicestate view=requestq - it names the program behind port $port. Stop that program and run Setup.bat again.");
    $names = array_column($svc, 1);
    say("Port $port is held by Windows' web driver, used by: " . implode(', ', $names) . '. Apache needs that port.');
    say('Stopping them and setting them to Disabled so they stay off after a restart (Revert.bat sets them back). Windows will ask once for permission: choose Yes.');
    $ps = '';
    foreach ($svc as [, $n, $mode]) {
        $m['services'][$n] ??= ['Auto' => 'auto', 'Disabled' => 'disabled'][$mode] ?? 'demand';
        $ps .= "sc.exe config '$n' start= disabled; if (\$LASTEXITCODE) { exit \$LASTEXITCODE }; net stop '$n' /y | Out-Null; ";
    }
    save();
    admin($ps . 'exit 0') === 0 || fail('Could not disable ' . implode(', ', $names) . '. Was the Windows permission prompt cancelled? Run Setup.bat again and accept it.');
    waitFor(fn() => !up($port), 20) || fail("Port $port is still taken after stopping " . implode(', ', $names) . '. In an administrator Command Prompt run: netsh http show servicestate view=requestq - it names the program behind the port.');
    say('[ok] ' . implode(', ', $names) . " stopped and disabled; port $port is free.");
}

/** Live updates by push need mod_proxy and mod_proxy_wstunnel (.htaccess hands /ws to the push server). XAMPP usually has them on already. Returns true if httpd.conf changed. */
function fixProxy(): bool {
    $t = $new = file_get_contents(HTTPD);
    $off = [];
    foreach (['proxy_module' => 'mod_proxy.so', 'proxy_wstunnel_module' => 'mod_proxy_wstunnel.so'] as $name => $file) {
        if (preg_match("/^\\s*LoadModule\\s+$name\\b/mi", $new) || !is_file(X . "/apache/modules/$file")) continue;
        if (preg_match("/^\\s*#\\s*(LoadModule\\s+$name\\b)/mi", $new)) {
            $new = preg_replace("/^\\s*#\\s*(LoadModule\\s+$name\\b)/mi", '$1', $new, 1);   // switched off: switch it on
        } else {
            $off[] = "LoadModule $name modules/$file";   // not mentioned at all: add it
        }
    }
    $new = $off ? rtrim($new) . "\n" . implode("\n", $off) . "\n" : $new;
    if ($new === $t) return false;
    backup(HTTPD, 'files/httpd.conf');
    file_put_contents(HTTPD, $new);
    if (run([X . '/apache/bin/httpd.exe', '-t', '-f', HTTPD], [], null, X)[0] !== 0) {
        file_put_contents(HTTPD, $t);   // Apache did not like it: put it back; pages just poll
        return false;
    }
    return true;
}

/**
 * Live updates (core/Push.php): starts the push server, replacing an old one so the new code is what runs. It never stops Setup:
 * without push the pages poll every 5 seconds. Another program's use of the push port is left alone.
 */
function startPush() {
    try {
        require_once APP . '/core/Push.php';
        if (Push::port() === null) return say('[!!] config.local.php has no push settings, so live updates use polling. Run Setup.bat again.');
        Push::quit();   // only answers to this app's own secret
        waitFor(fn() => !Push::running(), 5);
        if (Push::running()) return say('[!!] Port ' . Push::port() . ' is used by another program (left alone), so live updates use polling. To use push, change the port in config.local.php (push) and in .htaccess, then run Setup.bat again.');
        launch(PHP_BINARY, [APP . '/ws-server.php']);
        waitFor(fn() => Push::running(), 10) || throw new RuntimeException('it did not start; run php ws-server.php in a terminal to see why');
        $port = apachePort();
        $status = http('http://127.0.0.1' . ($port === 80 ? '' : ":$port") . '/school-supplies/ws')[0];
        say($status === 400   // 400 is the push server answering a plain page request: Apache did pass /ws on to it
            ? '[ok] Push server running: live updates arrive at once, and the app restarts it by itself if it ever stops.'
            : "[!!] The push server runs, but Apache does not pass /ws to it (HTTP $status), so pages poll every 5 seconds instead.");
    } catch (Throwable $e) {
        say('[!!] Push server not started (' . $e->getMessage() . '); live updates use polling.');
    }
}

function apache(): string {
    $port = apachePort();
    [$c, $o] = run([X . '/apache/bin/httpd.exe', '-t', '-f', HTTPD], [], null, X);
    $c === 0 || fail("Apache rejected its own settings (httpd.conf):\n" . trim($o) . "\nIf XAMPP was moved after it was installed, run setup_xampp.bat in the XAMPP folder, then Setup.bat again.");
    $start = function () use ($port) {
        launch(X . '/apache/bin/httpd.exe', []);
        waitFor(fn() => up($port), 30) || fail("Apache did not start. Most often another program (Skype, IIS, VMware...) already uses port $port or 443. The end of Apache's log says:\n" . tail(X . '/apache/logs/error.log'));
    };
    if (!up($port)) {
        say('[..] Starting Apache. If Windows Firewall asks: Cancel keeps the site on this PC only, Allow lets other computers on your network open it.');
        $start();
    }
    $base = 'http://127.0.0.1' . ($port === 80 ? '' : ":$port") . '/school-supplies/';
    [$s, $srv] = http($base . 'login.php');
    if ($s !== 200) {
        fail($srv !== '' && stripos($srv, 'apache') === false
            ? "Port $port is answered by another web server ($srv), not XAMPP's Apache. Stop that one (IIS, for example) and run Setup.bat again."
            : "The sign-in page answered with HTTP $s instead of opening. Look at " . X . '/apache/logs/error.log and ' . X . '/php/logs/php_error_log.');
    }
    $hidden = fn() => http($base . 'config.local.php')[0] === 403 && http($base . 'database/inventory.sql')[0] === 403;
    if (!$hidden() && fixOverride()) {
        stopApache($port);
        $start();
    }
    if (!$hidden()) {
        stopApache($port);
        fail('The web server would let anyone download config.local.php or the .sql files (they should answer "Forbidden"), so Apache was stopped again. In ' . HTTPD . ' the htdocs folder needs AllowOverride All; then run Setup.bat again.');
    }
    if (fixProxy()) {   // restart once so Apache loads the proxy modules
        stopApache($port);
        $start();
    }
    say('[ok] Apache is running; config.local.php and the .sql files answer "Forbidden" as they should.');
    return 'http://localhost' . ($port === 80 ? '' : ":$port") . '/school-supplies/';
}

// ---------------------------------------------------------------- the whole setup

/** Databases in XAMPP's data folder that are neither XAMPP's own nor this app's two (sc_inventory, sc_accounts). Read from the folder, so MySQL need not be running. */
function otherPrograms(): array {
    $all = array_map('basename', glob(DATA . '/*', GLOB_ONLYDIR) ?: []);
    $own = array_udiff($all, ['mysql', 'performance_schema', 'phpmyadmin', 'test', 'sys'], 'strcasecmp');
    return array_values(array_filter($own, fn($d) => !in_array(strtolower($d), ['sc_inventory', 'sc_accounts'], true)));
}

function setup() {
    global $m;
    if (!isset($m['users']) && ($others = otherPrograms())) {   // before anything is changed, the first time only
        say('This MySQL also holds databases of other programs: ' . implode(', ', $others) . '.');
        say('Setup never touches them. But it gives MariaDB root a password if it has none (README step 4), and any of those programs that sign in as root without a password will stop working until you put the new password in them. Setup.bat does update phpMyAdmin.');
        in_array(strtolower(ask('Continue? (y/n): ')), ['y', 'yes'], true) || fail('Nothing was changed. Run Setup.bat again when you are ready, or read README, Sharing MySQL with other programs.');
    }
    is_dir(BK) || mkdir(BK, 0777, true) || fail('Cannot write inside ' . X . '.');
    if (!isset($m['panel']) && is_file(X . '/xampp-control.exe')) {
        panelFlag('add');
        $m['panel'] = 1;
        save();
    }
    $web = apachePort();
    up($web) && stripos(http("http://127.0.0.1:$web/")[1], 'apache') === false && freePort($web);   // first of all, so no other problem can block it
    syncApp();
    $edited = fixIni();
    say($edited ? '[ok] my.ini edited: MySQL will listen on this PC only.' : '[ok] my.ini already keeps MySQL on this PC only.');
    fixPma();

    $running = up(PORT);
    if (!$running) {
        startMysql();
        say('[ok] MySQL started.');
    }
    $ready = installed();
    $ready || checkDatadir(root());
    if ($running && ($edited || exposed())) {
        say('[..] Restarting MySQL so the my.ini change takes effect.');
        root()->exec('SHUTDOWN');
        waitFor(fn() => !up(PORT), 60) || fail('MySQL did not stop.');
        startMysql();
    }
    exposed() && fail('MySQL still answers on this PC\'s network address. In my.ini, [mysqld] must hold bind-address=127.0.0.1 and no later line may override it. Then run Setup.bat again.');
    if ($ready) {
        say('[ok] Databases and app accounts already set up.');
    } else {
        hardenRoot(root());
        installDbs(root());
    }
    adminPassword();
    $url = apache();
    startPush();
    run(['cmd', '/c', 'start', '', $url]);
    say(PHP_EOL . "Done. The app is open at $url - sign in as admin.");
    say('MySQL and Apache keep running in the background; press Stop on both in the XAMPP Control Panel before shutting Windows down.');
    isset($m['panel']) && say('MySQL and Apache run as administrator, so the XAMPP Control Panel now asks for administrator permission when it opens: that is what lets it show and stop them. If it shows them as stopped anyway, do not press Start (that causes "Port 80 in use"): close the panel and open it again.');
    say('To undo everything this setup did, run Revert.bat.');
}

// ---------------------------------------------------------------- undo

function revert() {
    global $m, $rootPw;
    $m || fail('Nothing to undo: there is no setup record in ' . BK . '.');
    say("Revert puts back what Setup changed:\n  - my.ini, phpMyAdmin's config.inc.php and httpd.conf (if Setup touched them)\n  - the app files in htdocs (new ones removed, replaced ones restored)\n  - MariaDB: the app databases and accounts Setup created are removed (their data is saved to a file first); root gets its old password back (usually none) and the removed anonymous accounts return." . (isset($m['services']) ? "\n  - Windows services Setup disabled (" . implode(', ', array_keys($m['services'])) . ") get their old startup type back and start again at the next restart." : '') . "\nThis makes MySQL open to everyone on this PC again, as XAMPP ships it.");
    if (strtolower(trim(ask('Continue? [y/N] '))) !== 'y') return say('Cancelled; nothing was changed.');

    if (isset($m['users']) || isset($m['before'])) {
        up(PORT) || startMysql();
        $db = root();
        if (isset($m['before'])) {
            $drop = array_values(array_diff(array_intersect(['sc_inventory', 'sc_accounts'], $db->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN)), $m['before']['db']));
            if ($drop) {
                $file = BK . '/data-saved-' . date('Ymd-His') . '.sql';
                [$c, $o] = run([X . '/mysql/bin/mysqldump.exe', '--host=127.0.0.1', '--port=' . PORT, '-uroot', '--databases', ...$drop, "--result-file=$file"], ['MYSQL_PWD' => $rootPw]);
                ($c === 0 && is_file($file)) || fail("Could not save a copy of the data first, so nothing was deleted:\n" . trim($o));
                foreach ($drop as $d) $db->exec("DROP DATABASE `$d`");
                say('[ok] Removed ' . implode(' and ', $drop) . "; their data is saved in $file");
            }
            foreach (array_diff(['sc_inventory_app', 'sc_accounts_app'], $m['before']['user']) as $u) $db->exec("DROP USER IF EXISTS '$u'@'127.0.0.1'");
        }
        foreach ($m['users'] ?? [] as $u) {
            $a = acct($db, $u['user'], $u['host']);
            try {
                $u['create'] ?? throw new RuntimeException('its old settings were not recorded');
                if ($u['user'] === '') {
                    $db->exec($u['create']);
                    foreach ($u['grants'] as $g) $db->exec($g);
                } else {
                    $sql = preg_replace('/^CREATE USER/i', 'ALTER USER', $u['create']);
                    stripos($sql, 'IDENTIFIED') === false && $db->exec("ALTER USER $a IDENTIFIED BY ''");   // a blank password has no IDENTIFIED clause to replay
                    $db->exec($sql);
                }
            } catch (Throwable $e) {
                say("[!!] Could not restore account $a: " . $e->getMessage() . ($u['create'] ? "\n     Run this yourself as root: {$u['create']}" : ''));
            }
        }
        $db->exec('SHUTDOWN');
        waitFor(fn() => !up(PORT), 60);
        say('[ok] MariaDB accounts restored; MySQL stopped.');
    }
    up($web = apachePort()) && stopApache($web);

    foreach (['my.ini' => INI, 'config.inc.php' => PMA, 'httpd.conf' => HTTPD] as $n => $to) {
        is_file(BK . "/files/$n") && copy(BK . "/files/$n", $to);
    }
    isset($m['panel']) && panelFlag('delete');
    if ($svc = $m['services'] ?? []) admin(implode('; ', array_map(fn($n, $mode) => "sc.exe config '$n' start= $mode", array_keys($svc), $svc)));
    if (is_file(APP . '/core/Push.php')) {
        require_once APP . '/core/Push.php';
        Push::quit();   // the push server stops before its files are removed
    }
    foreach ($m['created'] ?? [] as $r) {
        @unlink(APP . "/$r");
        for ($d = dirname($r); $d !== '.'; $d = dirname($d)) @rmdir(APP . "/$d");
    }
    foreach ($m['replaced'] ?? [] as $r) copy(BK . "/app/$r", APP . "/$r");
    @rmdir(APP);
    $kept = BK . '-reverted-' . date('Ymd-His');
    rename(BK, $kept);
    say("[ok] Files restored. Everything is back as before; Apache and MySQL are stopped.\nThe record and any saved data are kept in $kept");
}

// ---------------------------------------------------------------- go

try {
    preflight();
    define('PORT', iniPort());
    if (is_file(BK . '/manifest.json')) {
        $m = json_decode(file_get_contents(BK . '/manifest.json'), true);
        if (!is_array($m)) {
            rename(BK . '/manifest.json', BK . '/manifest.corrupt-' . date('Ymd-His'));
            $m = [];
            say('[!!] The setup record was damaged and has been set aside; Revert can only restore files from the backup folder now.');
        }
    }
    in_array('--revert', $argv, true) ? revert() : setup();
} catch (Throwable $e) {
    say(PHP_EOL . 'Stopped: ' . $e->getMessage());
    say('Nothing is lost: run Setup.bat again to carry on from here, or Revert.bat to undo what was done.');
    exit(1);
}
