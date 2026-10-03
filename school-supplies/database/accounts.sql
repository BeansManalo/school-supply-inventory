-- The accounts database (3NF). Kept apart from the inventory, with its own MariaDB account, so a flaw
-- in the inventory code can never reach a password hash.
--   roles: id -> name (what each role may do is in core/Auth.php; a new role is one more row here and one more line there)
--   users: id -> username, password_hash, role_id, failed_attempts, locked_until, last_login_at, created_at, banned, requests_muted
--   requests: id -> user_id, role_id (the role asked for), note, status, created_at (see core/Requests.php)
--   settings: name -> value (one row for now: whether access requests are open)
-- Run by install.php (as root), which first creates the account named in the GRANTs below, and then adds the "admin" user. Whole-line comments only.

CREATE DATABASE IF NOT EXISTS sc_accounts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sc_accounts;

CREATE TABLE IF NOT EXISTS roles (
    id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(30) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY roles_name (name)
) ENGINE=InnoDB;

-- Usernames are unique without regard to case ("Admin" and "admin" are the same account).
-- password_hash is what password_hash() returns, never the password itself.
-- failed_attempts and locked_until implement the lockout (see core/Auth.php).
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(50) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role_id         TINYINT UNSIGNED NOT NULL,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    banned          TINYINT(1) NOT NULL DEFAULT 0,
    requests_muted  TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY users_username (username),
    KEY users_role (role_id),
    CONSTRAINT users_role_fk FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB;

-- banned = 1: the account can't sign in and an open session ends. This adds the column to databases made before bans existed.
ALTER TABLE users ADD COLUMN IF NOT EXISTS banned TINYINT(1) NOT NULL DEFAULT 0;

-- requests_muted = 1: the account can't ask for more access (the super admin's "mute sender"). Also added to older databases here.
ALTER TABLE users ADD COLUMN IF NOT EXISTS requests_muted TINYINT(1) NOT NULL DEFAULT 0;

-- A request for a bigger role. Deleting an account deletes its requests.
CREATE TABLE IF NOT EXISTS requests (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    role_id    TINYINT UNSIGNED NOT NULL,
    note       VARCHAR(200) NOT NULL DEFAULT '',
    status     ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY requests_user (user_id, created_at),
    KEY requests_status (status),
    CONSTRAINT requests_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT requests_role_fk FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    name  VARCHAR(30) NOT NULL,
    value VARCHAR(30) NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB;
INSERT IGNORE INTO settings (name, value) VALUES ('requests', 'on');

INSERT INTO roles (id, name) VALUES (1, 'super_admin'), (2, 'viewer'), (3, 'stock_clerk'), (4, 'inventory_manager') ON DUPLICATE KEY UPDATE name = name;

-- The app reads accounts, adds them (sign-up) and deletes them (super admin), and changes only the columns named below.
-- Roles can be read, never changed. Everything else about an account is fixed once it exists.
GRANT SELECT ON sc_accounts.roles TO 'sc_accounts_app'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON sc_accounts.users TO 'sc_accounts_app'@'127.0.0.1';
GRANT UPDATE (password_hash, role_id, failed_attempts, locked_until, last_login_at, banned, requests_muted) ON sc_accounts.users TO 'sc_accounts_app'@'127.0.0.1';
GRANT SELECT, INSERT ON sc_accounts.requests TO 'sc_accounts_app'@'127.0.0.1';
GRANT UPDATE (status) ON sc_accounts.requests TO 'sc_accounts_app'@'127.0.0.1';
GRANT SELECT ON sc_accounts.settings TO 'sc_accounts_app'@'127.0.0.1';
GRANT UPDATE (value) ON sc_accounts.settings TO 'sc_accounts_app'@'127.0.0.1';
