<?php
/**
 * Sign-in accounts, kept in the sc_accounts database (database/accounts.sql). This is the only place that knows where accounts are.
 * Every page except login.php needs a signed-in account (see includes/bootstrap.php). If the database can't be read, nobody gets in.
 */
class Auth {
    private const DUMMY = '$2y$10$hLwSL7l77x48/raPt/i/HueStI8uOhxjrxuC6l98HT8CygKRQAm8W';   // password_hash() of nothing real, so a missing username costs the same time as a wrong password
    private const MAX_FAILS = 5;       // wrong passwords in a row before the account is locked
    private const LOCK_MINUTES = 15;   // how long it stays locked

    /** True for the signed-in super admin. The Manager (save files, load, delete) is theirs alone. */
    public static function isSuperAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'super_admin';
    }

    /** The account with this username as ['id', 'username', 'role', 'hash', 'locked'], or null. 'locked' is true while a lockout is running. */
    public static function find(string $username): ?array {
        $statement = Db::get('accounts')->prepare(
            'SELECT u.id, u.username, r.name AS role, u.password_hash AS hash, u.locked_until > NOW() AS locked
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?'
        );
        $statement->execute([$username]);
        return $statement->fetch() ?: null;
    }

    /**
     * The account (without its hash) if the username and password match and it isn't locked, otherwise null.
     * A wrong password counts against the account; the fifth in a row locks it. A right one clears the count.
     */
    public static function attempt(string $username, string $password): ?array {
        $account = self::find($username);
        $match = password_verify($password, $account['hash'] ?? self::DUMMY);   // always runs, whether or not the username exists
        $db = Db::get('accounts');
        if ($account && $match && !$account['locked']) {
            $db->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$account['id']]);
            unset($account['hash'], $account['locked']);
            return $account;
        }
        if ($account && !$account['locked']) {   // while locked, further guesses neither count nor extend the lock
            $db->prepare(
                'UPDATE users SET locked_until = IF(failed_attempts + 1 >= ' . self::MAX_FAILS . ', NOW() + INTERVAL ' . self::LOCK_MINUTES . ' MINUTE, locked_until),
                                  failed_attempts = failed_attempts + 1 WHERE id = ?'
            )->execute([$account['id']]);
        }
        return null;
    }
}
