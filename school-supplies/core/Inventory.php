<?php
/**
 * The single data store for products and stock movements.
 *
 * Lives in the sc_inventory database (database/inventory.sql), is read into memory at the start of every request,
 * and every change is written to the database straight away. The tables (categories, products, movements) are the
 * same ones a save file holds, so Manager > Save / Load and the Documents backup (see Store) move the whole thing in and out.
 * In memory a product still carries its category name and stock level, which the pages use; stock is not stored,
 * it is the sum of a product's movements. The pages just call the public methods.
 */
class Inventory {
    private const BAD_QR = 'This is not a QR code made by this app, or it was edited.';
    private const MAX = ['id' => 50, 'name' => 150, 'category' => 50, 'note' => 500];   // characters: the column sizes in database/inventory.sql
    private const MAX_UNITS = 1000000;   // largest quantity or reorder level: also a CHECK in the database

    private array $products = [];   // id => Product
    private array $movements = [];  // StockMovement list, oldest first
    private array $categories = []; // id => name; only ever grows, so saved category ids stay stable
    private int $nextMovementId = 1;   // only used by hydrate(), to check that movement ids ascend
    private Store $store;
    private PDO $db;

    public function __construct() {
        $this->db = Db::get('inventory');
        $this->store = new Store();
        $this->hydrate($this->load());
    }

    public function products(): array {
        return $this->products;
    }

    public function product(string $id): ?Product {
        return $this->products[$id] ?? null;
    }

    /** Newest first. */
    public function movements(): array {
        $all = $this->movements;
        usort($all, fn($a, $b) => [$b->date, $b->id] <=> [$a->date, $a->id]);
        return $all;
    }

    /** Movements grouped by day, newest day first: ['Y-m-d' => [StockMovement, ...]]. */
    public function movementDays(): array {
        $days = [];
        foreach ($this->movements() as $m) {
            $days[substr($m->date, 0, 10)][] = $m;
        }
        return $days;
    }

    /** Counts for the dashboard. */
    public function summary(): array {
        $status = array_count_values(array_map(fn($p) => $p->status(), $this->products));
        return [
            'products' => count($this->products),
            'units'    => array_sum(array_map(fn($p) => $p->quantity, $this->products)),
            'categories' => count(array_filter(array_unique(array_map(fn($p) => $p->category, $this->products)))),
            'low'      => $status['Low'] ?? 0,
            'out'      => $status['Out'] ?? 0,
        ];
    }

    /** Units in and out for each of the last $days days, oldest first: ['Y-m-d' => ['in' => n, 'out' => n]]. */
    public function dailyTotals(int $days): array {
        $totals = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $totals[date('Y-m-d', strtotime("-$i days"))] = ['in' => 0, 'out' => 0];
        }
        foreach ($this->movements as $m) {
            if (isset($totals[$day = substr($m->date, 0, 10)])) {
                $totals[$day][$m->type] += $m->quantity;
            }
        }
        return $totals;
    }

    /** Units on hand per category, most first; uncategorized products are under ''. */
    public function unitsByCategory(): array {
        $units = [];
        foreach ($this->products as $p) {
            $units[$p->category] = ($units[$p->category] ?? 0) + $p->quantity;
        }
        arsort($units);
        return $units;
    }

    /** Adds a product ($isNew) or edits the one with this ID (a SKU or scanned barcode). Inputs are raw form strings. */
    public function saveProduct(bool $isNew, string $id, string $name, string $category, string $reorderLevel): void {
        $id = trim($id);
        $name = trim($name);
        $category = trim($category);
        if ($id === '' || $name === '') {
            throw new ValidationException('Product ID and name are required.');
        }
        if (mb_strlen($id) > self::MAX['id'] || mb_strlen($name) > self::MAX['name'] || mb_strlen($category) > self::MAX['category']) {
            throw new ValidationException('Too long: the product ID and category can be ' . self::MAX['id'] . ' characters, the name ' . self::MAX['name'] . '.');
        }
        $level = filter_var($reorderLevel, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_UNITS]]);
        if ($level === false) {
            throw new ValidationException('Reorder level must be a whole number from 0 to ' . number_format(self::MAX_UNITS) . '.');
        }
        $isNew && isset($this->products[$id]) && throw new ValidationException("Product ID $id already exists.");
        $product = $isNew ? null : ($this->product($id) ?? throw new ValidationException('Product not found.'));

        $this->transaction(function () use ($isNew, $id, $name, $category, $level, $product) {
            $categoryId = $this->categoryId($category);
            if ($isNew) {
                $this->run('INSERT INTO products (id, name, category_id, reorder_level) VALUES (?, ?, ?, ?)', [$id, $name, $categoryId, $level]);
                $this->products[$id] = new Product($id, $name, $category, $level);
                return;
            }
            $this->run('UPDATE products SET name = ?, category_id = ?, reorder_level = ? WHERE id = ?', [$name, $categoryId, $level, $id]);
            [$product->name, $product->category, $product->reorderLevel] = [$name, $category, $level];
        });
        $this->persist();
    }

    /**
     * The text for a QR code holding one or more products. $records is a list of [code, name, category, stock to add or ''].
     * The fields are written column by column (all codes, then all names, ...) with control characters as separators,
     * which deflates better than JSON or rows; Store::seal() then compresses and encrypts it. Codes and names must be
     * unique in the list, because a scan would otherwise create two products that clash with each other.
     */
    public function qrPayload(array $records): string {
        $clean = fn($v) => trim(preg_replace('/[\x00-\x1f]+/', ' ', (string) $v));   // the control characters are the separators
        $rows = $ids = $names = [];
        foreach (array_values($records) as $n => $r) {
            [$id, $name, $category, $stock] = array_map($clean, array_pad((array) $r, 4, ''));
            if ($id === '' || $name === '') {
                throw new ValidationException('Product ' . ($n + 1) . ': the product code and name are required.');
            }
            $qty = $stock === '' ? null : filter_var($stock, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($qty === false) {
                throw new ValidationException("“{$name}”: stock to add must be a whole number, 1 or more.");
            }
            $key = mb_strtolower($name);
            if (isset($ids[$id]) || isset($names[$key])) {
                throw new ValidationException("“{$name}” ($id) clashes with another product in the list: each code and each name can appear once.");
            }
            $ids[$id] = $names[$key] = true;
            $rows[] = [$id, $name, $category, (string) $qty];
        }
        $rows || throw new ValidationException('Add at least one product.');
        return $this->store->seal(implode("\x1e", array_map(fn($c) => implode("\x1f", array_column($rows, $c)), [0, 1, 2, 3])));
    }

    /**
     * Checks scanned QR text (see qrPayload) and compares each product in it with the inventory. Returns one entry per
     * product: its fields plus 'state': 'new' (not in the inventory), 'match', 'category' (same product, different
     * category: the user decides) or 'critical' (the code and name disagree with the inventory; 'problem' says how).
     * 'inventory' is the product with that code, if any. Throws ValidationException if the text is not an untouched
     * code made by this app.
     */
    public function scan(string $text): array {
        try {
            $cols = array_map(fn($c) => explode("\x1f", $c), explode("\x1e", $this->store->open($text)));
        } catch (RuntimeException) {
            throw new ValidationException(self::BAD_QR);
        }
        count($cols) === 4 && count(array_unique(array_map('count', $cols))) === 1 || throw new ValidationException(self::BAD_QR);

        $same = fn(string $a, string $b) => mb_strtolower(trim($a)) === mb_strtolower(trim($b));
        $byName = [];   // lower-case name => Product, so a big code doesn't search the whole inventory once per product
        foreach ($this->products as $p) {
            $byName[mb_strtolower(trim($p->name))] = $p;
        }
        $items = [];
        foreach (array_map(null, ...$cols) as [$id, $name, $category, $stock]) {
            $stock = $stock === '' ? null : (int) $stock;
            $inv = $this->product($id);
            $twin = $inv ? null : ($byName[mb_strtolower(trim($name))] ?? null);   // another code, same name
            $problem = '';
            if ($inv && !$same($inv->name, $name)) {
                $problem = "This QR code says $id is “{$name}”, but the inventory has $id as “{$inv->name}”.";
            } elseif ($twin) {
                $problem = "This QR code gives “{$name}” the code $id, but the inventory already has “{$twin->name}” as {$twin->id}.";
            }
            $state = $problem ? 'critical' : (!$inv ? 'new' : ($category !== '' && $category !== $inv->category ? 'category' : 'match'));
            $items[] = [
                'id' => $id, 'name' => $name, 'category' => $category, 'stock' => $stock, 'state' => $state, 'problem' => $problem,
                'inventory' => $inv ? ['category' => $inv->category, 'quantity' => $inv->quantity] : null,
            ];
        }
        return $items;
    }

    /**
     * Records the stock of every product in a scanned QR code, adding the new ones first. The text is checked again here,
     * so the fields the code holds can't be changed from the popup. $entries holds what the user filled in, by position
     * in the code: 'stock' if the code holds none, 'category' and 'reorder' (optional) for a new product, and 'choice'
     * ('keep' or 'overwrite') when the category differs. Products with a critical mismatch are skipped. Everything else
     * is saved together, or (if anything is invalid) nothing is. Returns the message to show.
     */
    public function applyScan(string $text, array $entries): string {
        $done = [];
        $new = $skipped = 0;
        $this->transaction(function () use ($text, $entries, &$done, &$new, &$skipped) {
            foreach ($this->scan($text) as $i => $s) {
                if ($s['state'] === 'critical') {
                    $skipped++;
                    continue;
                }
                $in = (array) ($entries[$i] ?? []);
                $choice = $in['choice'] ?? '';
                $s['state'] === 'category' && !in_array($choice, ['keep', 'overwrite'], true)
                    && throw new ValidationException("Choose what to do about the category of “{$s['name']}”.");

                $product = $this->product($s['id']);
                if (!$product) {
                    $this->saveProduct(true, $s['id'], $s['name'], $s['category'] ?: trim($in['category'] ?? ''), trim($in['reorder'] ?? '') ?: '0');
                    $new++;
                } elseif ($s['state'] === 'category' && $choice === 'overwrite') {
                    $this->saveProduct(false, $s['id'], $product->name, $s['category'], (string) $product->reorderLevel);
                }
                $this->recordMovement($s['id'], 'in', (string) ($s['stock'] ?? $in['stock'] ?? ''), 'Added via QR scan');
                $done[] = "“{$s['name']}”";
            }
            $done || throw new ValidationException('Nothing was added: every product in this code has a problem.');
        });
        $this->persist();

        return 'Added stock to ' . (count($done) <= 3 ? implode(', ', $done) : count($done) . ' products')
            . ($new ? " ($new new)" : '') . '.' . ($skipped ? " Skipped $skipped with a problem." : '');
    }

    /** Also removes the product's movement history. */
    public function deleteProduct(string $id): void {
        $this->transaction(function () use ($id) {
            $this->run('DELETE FROM products WHERE id = ?', [$id]);   // its movements go with it (ON DELETE CASCADE)
            unset($this->products[$id]);
            $this->movements = array_values(array_filter($this->movements, fn($m) => $m->productId !== $id));
        });
        $this->persist();
    }

    /** $type is 'in' or 'out'. Updates the product's quantity and logs the movement. */
    public function recordMovement(string $productId, string $type, string $quantity, string $note = '', string $date = ''): void {
        $product = $this->product($productId) ?? throw new ValidationException('Select a product.');
        if (!in_array($type, ['in', 'out'], true)) {
            throw new ValidationException('Select stock in or stock out.');
        }
        $qty = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_UNITS]]);
        if ($qty === false) {
            throw new ValidationException('Quantity must be a whole number from 1 to ' . number_format(self::MAX_UNITS) . '.');
        }
        $note = trim($note);
        if (mb_strlen($note) > self::MAX['note']) {
            throw new ValidationException('The note can be ' . self::MAX['note'] . ' characters at most.');
        }
        $date = $date ?: date('Y-m-d H:i');

        $this->transaction(function () use ($product, $type, $qty, $note, $date) {
            // Lock the product first, then read its stock from the database (not from the copy loaded at the start of the request),
            // so two people taking the last units at the same moment can't both succeed.
            $this->run('SELECT 1 FROM products WHERE id = ? FOR UPDATE', [$product->id])->fetchColumn() ?: throw new ValidationException('Product not found.');
            $stock = (int) $this->run("SELECT COALESCE(SUM(IF(type = 'in', quantity, -quantity)), 0) FROM movements WHERE product_id = ?", [$product->id])->fetchColumn();
            if ($type === 'out' && $qty > $stock) {
                throw new ValidationException("Only $stock in stock.");
            }
            $this->run("INSERT INTO movements (product_id, type, quantity, note, `date`) VALUES (?, ?, ?, ?, STR_TO_DATE(?, '%Y-%m-%d %H:%i'))", [$product->id, $type, $qty, $note, $date]);
            $product->quantity = $stock + ($type === 'in' ? $qty : -$qty);
            $this->movements[] = new StockMovement((int) $this->db->lastInsertId(), $product->id, $type, $qty, $note, $date);
        });
        $this->persist();
    }

    /** The whole inventory as the bytes of a save file. */
    public function export(): string {
        return $this->store->bytes($this->tables());
    }

    /** Replaces everything with a save file's content. Throws ValidationException, leaving the database untouched, if it isn't a valid save. */
    public function import(string $bytes): void {
        try {
            $this->hydrate($this->store->decode($bytes));   // checks the whole file before anything is written
        } catch (Throwable) {
            throw new ValidationException('That file is not a valid inventory save, or it was edited.');
        }
        $tables = $this->tables();
        $this->transaction(function () use ($tables) {
            $this->wipeRows();
            foreach ([
                'INSERT INTO categories (id, name) VALUES (?, ?)' => $tables['categories'],
                'INSERT INTO products (id, name, category_id, reorder_level) VALUES (?, ?, ?, ?)' => $tables['products'],
                "INSERT INTO movements (id, product_id, type, quantity, note, `date`) VALUES (?, ?, ?, ?, ?, STR_TO_DATE(?, '%Y-%m-%d %H:%i'))" => $tables['movements'],
            ] as $sql => $rows) {   // each row's values are in the column order above
                $insert = $this->db->prepare($sql);
                foreach ($rows as $row) {
                    $insert->execute(array_values($row));
                }
            }
        });
        $this->store->replace($bytes);
    }

    /** Deletes every product, category and movement from the database, and the Documents backup. The next request starts empty. */
    public function wipe(): void {
        $this->transaction(fn() => $this->wipeRows());
        $this->store->wipe();
    }

    /** The database already has the change; this refreshes the Documents backup (at most hourly) once no transaction is pending. */
    private function persist(): void {
        $this->db->inTransaction() || $this->store->backup(fn() => $this->tables());
    }

    /** The tables as the database holds them, in the shape of a save file. One transaction, so they all come from the same moment. */
    private function load(): array {
        $all = fn(string $sql) => $this->db->query($sql)->fetchAll();
        return $this->transaction(fn() => [
            'version' => 1,
            'categories' => $all('SELECT id, name FROM categories ORDER BY id'),
            'products' => $all('SELECT id, name, category_id, reorder_level FROM products'),
            'movements' => $all("SELECT id, product_id, type, quantity, note, DATE_FORMAT(`date`, '%Y-%m-%d %H:%i') AS `date` FROM movements ORDER BY id"),
        ]);
    }

    /** Runs $work in a transaction (or inside the one already open) and rolls everything back if it throws. */
    private function transaction(callable $work): mixed {
        if ($this->db->inTransaction()) {
            return $work();
        }
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $ex) {
            $this->db->inTransaction() && $this->db->rollBack();
            throw $ex;
        }
    }

    private function run(string $sql, array $params = []): PDOStatement {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** The id of this category, adding it if it is new; null for no category. */
    private function categoryId(string $name): ?int {
        if ($name === '') {
            return null;
        }
        if (($id = array_search($name, $this->categories, true)) === false) {
            $this->run('INSERT INTO categories (name) VALUES (?)', [$name]);
            $this->categories[$id = (int) $this->db->lastInsertId()] = $name;
        }
        return $id;
    }

    private function wipeRows(): void {
        $this->db->exec('DELETE FROM products');   // their movements go with them (ON DELETE CASCADE)
        $this->db->exec('DELETE FROM categories');
    }

    /** The model as normalized tables. Stock levels are not stored: they are the sum of each product's movements. */
    private function tables(): array {
        $ids = array_flip($this->categories);   // name => id
        return [
            'version' => 1,
            'categories' => array_map(fn($id, $name) => ['id' => $id, 'name' => $name], array_keys($this->categories), $this->categories),
            'products' => array_map(fn($p) => [
                'id' => $p->id, 'name' => $p->name, 'category_id' => $ids[$p->category] ?? null, 'reorder_level' => $p->reorderLevel,
            ], array_values($this->products)),
            'movements' => array_map(fn($m) => [
                'id' => $m->id, 'product_id' => $m->productId, 'type' => $m->type, 'quantity' => $m->quantity, 'note' => $m->note, 'date' => $m->date,
            ], $this->movements),
        ];
    }

    /** Rebuilds the model from saved tables, enforcing what a database would: keys, foreign keys, domains, no negative stock. */
    private function hydrate(array $t): bool {
        $ok = fn(bool $condition) => $condition || throw new RuntimeException('Invalid save data.');
        $fits = fn($s, string $field) => is_string($s) && $s !== '' && mb_strlen($s) <= self::MAX[$field];
        $ok(($t['version'] ?? null) === 1);
        $this->categories = $this->products = $this->movements = [];
        $this->nextMovementId = 1;

        foreach ($t['categories'] as $c) {
            $ok(is_int($c['id']) && $c['id'] > 0 && !isset($this->categories[$c['id']])
                && $fits($c['name'], 'category') && !in_array($c['name'], $this->categories, true));
            $this->categories[$c['id']] = $c['name'];
        }
        foreach ($t['products'] as $r) {
            $ok($fits($r['id'], 'id') && !isset($this->products[$r['id']])
                && $fits($r['name'], 'name')
                && ($r['category_id'] === null || isset($this->categories[$r['category_id']]))
                && is_int($r['reorder_level']) && $r['reorder_level'] >= 0 && $r['reorder_level'] <= self::MAX_UNITS);
            $this->products[$r['id']] = new Product($r['id'], $r['name'], $this->categories[$r['category_id']] ?? '', $r['reorder_level']);
        }
        foreach ($t['movements'] as $r) {   // oldest first, so stock is rebuilt in the order it really changed
            $p = $this->products[$r['product_id']] ?? null;
            $ok($p && is_int($r['id']) && $r['id'] >= $this->nextMovementId
                && in_array($r['type'], ['in', 'out'], true) && is_int($r['quantity']) && $r['quantity'] >= 1 && $r['quantity'] <= self::MAX_UNITS
                && is_string($r['note']) && mb_strlen($r['note']) <= self::MAX['note'] && is_string($r['date']) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $r['date']));
            $p->quantity += $r['type'] === 'in' ? $r['quantity'] : -$r['quantity'];
            $ok($p->quantity >= 0);
            $this->movements[] = new StockMovement($r['id'], $p->id, $r['type'], $r['quantity'], $r['note'], $r['date']);
            $this->nextMovementId = $r['id'] + 1;
        }
        return true;
    }
}
