-- The inventory database: the same three tables a .scinvent save file holds (3NF).
--   categories: id -> name
--   products:   id -> name, category_id, reorder_level
--   movements:  id -> product_id, type, quantity, note, date
-- Stock is not stored anywhere: it is the sum of a product's movements, so it can never disagree with the history.
-- Run by install.php (as root), which first creates the account named in the GRANTs below. Whole-line comments only.

CREATE DATABASE IF NOT EXISTS sc_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sc_inventory;

-- utf8mb4_bin on ids and category names: exact, case-sensitive matching, like the PHP arrays the app used before.
CREATE TABLE IF NOT EXISTS categories (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id            VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    name          VARCHAR(150) NOT NULL,
    category_id   INT UNSIGNED NULL,
    reorder_level INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY products_category (category_id),
    CONSTRAINT products_category_fk FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT products_reorder CHECK (reorder_level <= 1000000)
) ENGINE=InnoDB;

-- Deleting a product deletes its history (ON DELETE CASCADE), as the app always did.
CREATE TABLE IF NOT EXISTS movements (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    type       ENUM('in', 'out') NOT NULL,
    quantity   INT UNSIGNED NOT NULL,
    note       VARCHAR(500) NOT NULL DEFAULT '',
    `date`     DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY movements_product (product_id),
    KEY movements_date (`date`),
    CONSTRAINT movements_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT movements_quantity CHECK (quantity BETWEEN 1 AND 1000000)
) ENGINE=InnoDB;

-- What the app may do, and nothing more. No CREATE/ALTER/DROP, no access to any other database.
-- Categories are never renamed and movements are never edited or deleted one by one, so there is no UPDATE on those.
-- (Removing a product still removes its movements: the database does that itself through the foreign key.)
GRANT SELECT, INSERT, DELETE ON sc_inventory.categories TO 'sc_inventory_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON sc_inventory.products TO 'sc_inventory_app'@'127.0.0.1';
GRANT SELECT, INSERT ON sc_inventory.movements TO 'sc_inventory_app'@'127.0.0.1';
