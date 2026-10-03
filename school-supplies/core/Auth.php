<?php
/**
 * Sign-in accounts, kept in the sc_accounts database (database/accounts.sql). This is the only place that knows where accounts are.
 * Every page except login.php and signup.php needs a signed-in account (see includes/bootstrap.php). If the database can't be read, nobody gets in.
 */
class Auth {
    private const DUMMY = '$2y$10$hLwSL7l77x48/raPt/i/HueStI8uOhxjrxuC6l98HT8CygKRQAm8W';   // password_hash() of nothing real, so a missing username costs the same time as a wrong password
    private const MAX_FAILS = 5;       // wrong passwords in a row before the account is locked
    private const LOCK_MINUTES = 15;   // how long it stays locked
    private const NOT_SUPER = " AND role_id <> (SELECT id FROM roles WHERE name = 'super_admin')";   // the super admin's own account is never changed through the account page

    /**
     * What each role may do besides view (everyone signed in can view and make reports): 'stock' records stock in/out,
     * 'products' adds, edits and deletes products and uses the QR tools. Only the super admin also has the account page's
     * Accounts and Database parts (isSuperAdmin()). A new role: one line here and one row in database/accounts.sql.
     */
    private const CAN = [
        'viewer'            => [],
        'stock_clerk'       => ['stock'],
        'inventory_manager' => ['stock', 'products'],
        'super_admin'       => ['stock', 'products'],
    ];
    private const DEFAULT_ROLE = 'viewer';   // what sign-up gives

    /** True for the signed-in super admin: the account page's Accounts and Database parts (save files, load, delete) are theirs alone. */
    public static function isSuperAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'super_admin';
    }

    /** Can the signed-in account do this ('stock' or 'products')? Pages use it to hide what a role can't do; endpoints use need(). */
    public static function can(string $ability): bool {
        return in_array($ability, self::CAN[$_SESSION['user']['role'] ?? ''] ?? [], true);
    }

    /** For endpoints: stops with a 403 unless the signed-in account can do this. */
    public static function need(string $ability): void {
        if (!self::can($ability)) {
            http_response_code(403);
            exit('Your role cannot do that.');
        }
    }

    /** "stock_clerk" -> "Stock clerk". */
    public static function label(string $role): string {
        return ucfirst(str_replace('_', ' ', $role));
    }

    /** The roles the super admin can give to other accounts (every role but super_admin). */
    public static function assignable(): array {
        return array_values(array_diff(array_keys(self::CAN), ['super_admin']));
    }

    /** The roles above this one that can be asked for: assignable roles that can do more. */
    public static function above(string $role): array {
        return array_values(array_filter(self::assignable(), fn($r) => count(self::CAN[$r]) > count(self::CAN[$role] ?? [])));
    }

    /** Re-reads the signed-in account on every request, so a role change, a ban or a deleted account takes effect at once. */
    public static function refresh(): void {
        $statement = Db::get('accounts')->prepare('SELECT u.id, u.username, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.banned = 0');
        $statement->execute([$_SESSION['user']['id']]);
        if ($account = $statement->fetch()) {
            $_SESSION['user'] = $account;
        } else {
            unset($_SESSION['user']);
        }
    }

    /** The account with this username as ['id', 'username', 'role', 'hash', 'locked', 'banned'], or null. 'locked' is true while a lockout is running. */
    public static function find(string $username): ?array {
        $statement = Db::get('accounts')->prepare(
            'SELECT u.id, u.username, r.name AS role, u.password_hash AS hash, u.locked_until > NOW() AS locked, u.banned, u.failed_attempts AS fails
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?'
        );
        $statement->execute([$username]);
        return $statement->fetch() ?: null;
    }

    /**
     * The account (without its hash) if the username and password match and it isn't locked, otherwise null.
     * A wrong password counts against the account; the fifth in a row locks it. A right one clears the count.
     * A banned account with the right password gets ['banned' => true] instead, so only someone who knows the password learns of the ban.
     */
    public static function attempt(string $username, string $password): ?array {
        $account = self::find($username);
        $match = password_verify($password, $account['hash'] ?? self::DUMMY);   // always runs, whether or not the username exists
        $db = Db::get('accounts');
        if ($account && $match && $account['banned']) {
            return ['banned' => true];
        }
        if ($account && $match && !$account['locked']) {
            $db->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$account['id']]);
            Push::poke(['admin']);   // the Accounts list shows the last sign-in
            unset($account['hash'], $account['locked'], $account['banned'], $account['fails']);
            return $account;
        }
        if ($account && !$account['locked']) {   // while locked, further guesses neither count nor extend the lock
            $db->prepare(
                'UPDATE users SET locked_until = IF(failed_attempts + 1 >= ' . self::MAX_FAILS . ', NOW() + INTERVAL ' . self::LOCK_MINUTES . ' MINUTE, locked_until),
                                  failed_attempts = failed_attempts + 1 WHERE id = ?'
            )->execute([$account['id']]);
            $account['fails'] + 1 >= self::MAX_FAILS && Push::poke(['admin']);   // that was the lockout
        }
        return null;
    }

    /** Sign-up: adds a viewer account. Returns what is wrong with the input, or null when the account was created. */
    public static function register(string $username, string $password, string $confirm): ?string {
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            return 'The username needs 3 to 50 letters, numbers, dots, dashes or underscores.';
        }
        if ($problem = self::passwordProblem($password, $confirm)) {
            return $problem;
        }
        try {
            Db::get('accounts')->prepare('INSERT INTO users (username, password_hash, role_id) SELECT ?, ?, id FROM roles WHERE name = ?')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), self::DEFAULT_ROLE]);
        } catch (PDOException $ex) {
            if (($ex->errorInfo[1] ?? 0) === 1062) {   // duplicate key: usernames are unique whatever their case
                return 'That username is taken.';
            }
            throw $ex;
        }
        Push::poke(['admin']);   // a new account for the Accounts list
        return null;
    }

    /** At least 8 characters, typed twice the same. Returns what is wrong, or null. */
    public static function passwordProblem(string $password, string $confirm): ?string {
        return strlen($password) < 8 ? 'The password needs at least 8 characters.' : ($password !== $confirm ? 'The two passwords do not match.' : null);
    }

    /** The signed-in account changes its own password. Returns what is wrong, or null when it was changed. */
    public static function changePassword(string $current, string $new, string $confirm): ?string {
        if (!password_verify($current, self::find($_SESSION['user']['username'])['hash'] ?? self::DUMMY)) {
            return 'The current password is wrong.';
        }
        if ($problem = self::passwordProblem($new, $confirm)) {
            return $problem;
        }
        Db::get('accounts')->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user']['id']]);
        return null;
    }

    /** Every account for the super admin's list: id, username, role, banned, requests_muted, locked (true while a lockout runs), locked_until, last_login_at. The super admin comes first. */
    public static function all(): array {
        return Db::get('accounts')->query(
            "SELECT u.id, u.username, r.name AS role, u.banned, u.requests_muted, u.locked_until > NOW() AS locked, u.locked_until, u.last_login_at
             FROM users u JOIN roles r ON r.id = u.role_id ORDER BY r.name = 'super_admin' DESC, u.username"
        )->fetchAll();
    }

    // The actions below are the super admin's (account.php checks that). Each returns true if it changed an account.
    // None of them touches a super admin account, and a role can only be one of assignable().

    public static function setRole(int $id, string $role): bool {
        return in_array($role, self::assignable(), true)
            && self::other('UPDATE users SET role_id = (SELECT id FROM roles WHERE name = ?) WHERE id = ?', [$role, $id]);
    }

    /** A banned account can't sign in, and a session it has open ends on its next click. Unbanning gives it back its role. */
    public static function ban(int $id, bool $banned): bool {
        return self::other('UPDATE users SET banned = ? WHERE id = ?', [(int) $banned, $id]);
    }

    /** Removes the lockout, which also forgives the wrong passwords counted so far. */
    public static function unlock(int $id): bool {
        return self::other('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
    }

    /** A new password for someone who forgot theirs; it also removes a lockout. */
    public static function resetPassword(int $id, string $password): bool {
        return self::other('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public static function delete(int $id): bool {
        return self::other('DELETE FROM users WHERE id = ?', [$id]);
    }

    /** Runs a statement ending in "WHERE id = ?" on an account that is not a super admin. */
    private static function other(string $sql, array $params): bool {
        $statement = Db::get('accounts')->prepare($sql . self::NOT_SUPER);
        $statement->execute($params);
        $changed = $statement->rowCount() > 0;
        $changed && Push::poke(['u' . (int) end($params), 'admin']);   // ends with the account id; the open pages ask poll.php what changed
        return $changed;
    }
}
