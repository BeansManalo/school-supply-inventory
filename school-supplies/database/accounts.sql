-- The accounts database (3NF). Kept apart from the inventory, with its own MariaDB account, so a flaw
-- in the inventory code can never reach a password hash.
--   roles: id -> name
--   users: id -> username, password_hash, role_id, failed_attempts, locked_until, last_login_at, created_at
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
    PRIMARY KEY (id),
    UNIQUE KEY users_username (username),
    KEY users_role (role_id),
    CONSTRAINT users_role_fk FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB;

INSERT INTO roles (id, name) VALUES (1, 'super_admin') ON DUPLICATE KEY UPDATE name = name;

-- The sign-in page reads accounts and updates only the three lockout/audit columns.
-- When you add account management, widen this (INSERT, and UPDATE on password_hash / role_id) deliberately.
GRANT SELECT ON sc_accounts.roles TO 'sc_accounts_app'@'127.0.0.1';
GRANT SELECT ON sc_accounts.users TO 'sc_accounts_app'@'127.0.0.1';
GRANT UPDATE (failed_attempts, locked_until, last_login_at) ON sc_accounts.users TO 'sc_accounts_app'@'127.0.0.1';
