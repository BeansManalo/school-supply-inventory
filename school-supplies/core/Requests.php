<?php
/**
 * Access requests: an account asks the super admin for a bigger role (tables requests and settings, database/accounts.sql).
 * An account can send one request every COOLDOWN_HOURS, whatever became of the last one, and none while one is waiting.
 * The super admin answers in the mailbox on the account page, can turn requests off for everyone, or mute one sender.
 */
class Requests {
    private const COOLDOWN_HOURS = 24;

    private static function db(): PDO {
        return Db::get('accounts');
    }

    /** Are requests turned on? The super admin's switch. */
    public static function open(): bool {
        return self::db()->query("SELECT value FROM settings WHERE name = 'requests'")->fetchColumn() === 'on';
    }

    public static function setOpen(bool $on): bool {
        $done = self::db()->prepare("UPDATE settings SET value = ? WHERE name = 'requests'")->execute([$on ? 'on' : 'off']);
        Push::poke(['all']);   // everyone's request panel
        return $done;
    }

    /**
     * What the signed-in account sees: 'off' (requests are off for everyone, or muted for this account), 'options' (the roles it
     * could ask for; empty means nothing to ask for, so no panel), and 'last' (its latest request: status, role, next, waiting) or null.
     * 'next' is when it may ask again; 'waiting' is true until then.
     */
    public static function mine(): array {
        $id = $_SESSION['user']['id'];
        $muted = self::db()->prepare('SELECT requests_muted FROM users WHERE id = ?');
        $muted->execute([$id]);
        $last = self::db()->prepare(
            'SELECT r.status, o.name AS role, r.created_at + INTERVAL ' . self::COOLDOWN_HOURS . ' HOUR AS next,
                    r.created_at + INTERVAL ' . self::COOLDOWN_HOURS . ' HOUR > NOW() AS waiting
             FROM requests r JOIN roles o ON o.id = r.role_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1'
        );
        $last->execute([$id]);
        return [
            'off'     => !self::open() || $muted->fetchColumn(),
            'options' => Auth::above($_SESSION['user']['role']),
            'last'    => $last->fetch() ?: null,
        ];
    }

    /** The signed-in account asks for a role. Returns what is wrong, or null when the request was sent. */
    public static function send(string $role, string $note): ?string {
        $mine = self::mine();
        if ($mine['off']) {
            return 'Access requests are not available right now.';
        }
        if (!in_array($role, $mine['options'], true)) {
            return 'Choose a role to ask for.';
        }
        $id = $_SESSION['user']['id'];
        $statement = self::db()->prepare(   // one statement, so the "nothing waiting, nothing recent" check and the insert can't be split
            "INSERT INTO requests (user_id, role_id, note) SELECT ?, id, ? FROM roles WHERE name = ?
             AND NOT EXISTS (SELECT 1 FROM requests WHERE user_id = ? AND (status = 'pending' OR created_at > NOW() - INTERVAL " . self::COOLDOWN_HOURS . ' HOUR))'
        );
        $statement->execute([$id, mb_substr(trim($note), 0, 200), $role, $id]);
        $statement->rowCount() && Push::poke(['admin']);   // new mail
        return $statement->rowCount() ? null : 'You already have a request waiting, or sent one recently.';
    }

    // Everything below is the super admin's (account.php checks that).

    /** Requests waiting for an answer, oldest first: id, username, has (their role), want (the role asked for), note, created_at, muted. */
    public static function pending(): array {
        return self::db()->query(
            "SELECT r.id, u.username, cur.name AS has, want.name AS want, r.note, r.created_at, u.requests_muted AS muted
             FROM requests r JOIN users u ON u.id = r.user_id JOIN roles cur ON cur.id = u.role_id JOIN roles want ON want.id = r.role_id
             WHERE r.status = 'pending' ORDER BY r.id"
        )->fetchAll();
    }

    /** How many are waiting: the number on the account button. */
    public static function count(): int {
        return (int) self::db()->query("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn();
    }

    public static function approve(int $id): bool {
        if (!$row = self::waiting($id)) {
            return false;
        }
        Auth::setRole($row['user_id'], $row['role']);   // false only if the account already has that role, which still settles the request
        return self::close($id, 'approved', $row['user_id']);
    }

    public static function deny(int $id): bool {
        return ($row = self::waiting($id)) && self::close($id, 'denied', $row['user_id']);
    }

    /** Denies the request and stops its sender from asking again until unmuted. */
    public static function muteSender(int $id): bool {
        return ($row = self::waiting($id)) && self::close($id, 'denied', $row['user_id']) && self::setMuted($row['user_id'], true);
    }

    public static function setMuted(int $userId, bool $muted): bool {
        $statement = self::db()->prepare('UPDATE users SET requests_muted = ? WHERE id = ?');
        $statement->execute([(int) $muted, $userId]);
        $statement->rowCount() && Push::poke(["u$userId", 'admin']);
        return $statement->rowCount() > 0;
    }

    private static function waiting(int $id): ?array {
        $statement = self::db()->prepare("SELECT r.user_id, o.name AS role FROM requests r JOIN roles o ON o.id = r.role_id WHERE r.id = ? AND r.status = 'pending'");
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    private static function close(int $id, string $status, int $userId): bool {
        $statement = self::db()->prepare("UPDATE requests SET status = ? WHERE id = ? AND status = 'pending'");
        $statement->execute([$status, $id]);
        $statement->rowCount() && Push::poke(["u$userId", 'admin']);   // the sender's panel and the mailbox
        return $statement->rowCount() > 0;
    }
}
