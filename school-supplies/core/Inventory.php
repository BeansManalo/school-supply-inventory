<?php
/**
 * The single data store for products and stock movements.
 *
 * Loaded from and saved to local files through Store. Every change is written straight away.
 * The saved tables are normalized (categories, products, movements) so they map 1:1 onto SQL tables;
 * in memory a product still carries its category name and stock level, which the pages use.
 * When SQL is added, only the method bodies here change -- the pages just call these methods.
 */
class Inventory {
    private const QR_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private array $products = [];   // id => Product
    private array $movements = [];  // StockMovement list, oldest first
    private array $categories = []; // id => name; only ever grows, so saved category ids stay stable
    private int $nextMovementId = 1;
    private Store $store;

    public function __construct() {
        $this->store = new Store();
        $this->store->read(fn($tables) => $this->hydrate($tables));   // no save yet = start empty; the first change creates the files
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
        $level = filter_var($reorderLevel, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($level === false) {
            throw new ValidationException('Reorder level must be a whole number, 0 or more.');
        }

        if ($isNew) {
            isset($this->products[$id]) && throw new ValidationException("Product ID $id already exists.");
            $this->products[$id] = new Product($id, $name, $category, $level);
            $this->persist();
            return;
        }
        $product = $this->product($id) ?? throw new ValidationException('Product not found.');
        $product->name = $name;
        $product->category = $category;
        $product->reorderLevel = $level;
        $this->persist();
    }

    /**
     * The text for a product QR code: JSON [code, name, category, stock to add or null, key].
     * The key is the hash of the other four, which is how a reader knows the content is exactly what was encoded.
     */
    public function qrPayload(string $id, string $name, string $category, string $stock): string {
        $id = trim($id);
        $name = trim($name);
        if ($id === '' || $name === '') {
            throw new ValidationException('Product code and name are required.');
        }
        $qty = trim($stock) === '' ? null : filter_var($stock, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($qty === false) {
            throw new ValidationException('Stock to add must be a whole number, 1 or more.');
        }
        $fields = [$id, $name, trim($category), $qty];
        return json_encode([...$fields, $this->store->sign(json_encode($fields, self::QR_FLAGS))], self::QR_FLAGS);
    }

    /**
     * Checks scanned QR text (see qrPayload) and compares it with the inventory. Returns the scanned fields plus
     * 'state': 'new' (not in the inventory), 'match', 'category' (same product, different category: the user decides)
     * or 'critical' (the code and name disagree with the inventory; 'problem' says how). 'inventory' is the product
     * with that code, if any. Throws ValidationException if the text is not an untouched code made by this app.
     */
    public function scan(string $text): array {
        $f = json_decode($text, true);
        $valid = is_array($f) && array_keys($f) === [0, 1, 2, 3, 4]
            && is_string($f[0]) && $f[0] !== '' && is_string($f[1]) && $f[1] !== '' && is_string($f[2]) && is_string($f[4])
            && ($f[3] === null || (is_int($f[3]) && $f[3] >= 1));
        $valid && hash_equals($this->store->sign(json_encode(array_slice($f, 0, 4), self::QR_FLAGS)), $f[4])
            || throw new ValidationException('This is not a QR code made by this app, or it was edited.');
        [$id, $name, $category, $stock] = $f;

        $same = fn(string $a, string $b) => mb_strtolower(trim($a)) === mb_strtolower(trim($b));
        $inv = $this->product($id);
        $twin = $inv ? null : current(array_filter($this->products, fn($p) => $same($p->name, $name)));   // another code, same name
        $problem = '';
        if ($inv && !$same($inv->name, $name)) {
            $problem = "This QR code says $id is “{$name}”, but the inventory has $id as “{$inv->name}”.";
        } elseif ($twin) {
            $problem = "This QR code gives “{$name}” the code $id, but the inventory already has “{$twin->name}” as {$twin->id}.";
        }
        $state = $problem ? 'critical' : (!$inv ? 'new' : ($category !== '' && $category !== $inv->category ? 'category' : 'match'));
        return [
            'id' => $id, 'name' => $name, 'category' => $category, 'stock' => $stock, 'state' => $state, 'problem' => $problem,
            'inventory' => $inv ? ['category' => $inv->category, 'quantity' => $inv->quantity] : null,
        ];
    }

    /**
     * Records the stock of a scanned QR code, adding the product first if it is new. The text is checked again here, so the
     * fields the code holds can't be changed from the popup. The rest is what the user filled in: $category and $reorderLevel
     * for a new product, $stock if the code holds none, $choice ('keep' or 'overwrite') when the category differs.
     * Returns the message to show.
     */
    public function applyScan(string $text, string $stock, string $category, string $reorderLevel, string $choice): string {
        $s = $this->scan($text);
        $s['state'] === 'critical' && throw new ValidationException($s['problem']);
        $qty = $s['stock'] ?? $stock;
        filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            && throw new ValidationException('Stock to add must be a whole number, 1 or more.');
        $s['state'] === 'category' && !in_array($choice, ['keep', 'overwrite'], true)
            && throw new ValidationException('Choose what to do about the category.');

        $product = $this->product($s['id']);
        if (!$product) {
            $this->saveProduct(true, $s['id'], $s['name'], $s['category'] ?: $category, $reorderLevel);
        } elseif ($s['state'] === 'category' && $choice === 'overwrite') {
            $this->saveProduct(false, $s['id'], $product->name, $s['category'], (string) $product->reorderLevel);
        }
        $this->recordMovement($s['id'], 'in', (string) $qty, 'Added via QR scan');

        $name = $product?->name ?? $s['name'];
        return ($product ? "Added $qty to “{$name}”." : "Added new product “{$name}” with $qty in stock.")
            . ($s['state'] === 'category' && $choice === 'overwrite' ? " Its category is now “{$s['category']}”." : '');
    }

    /** Also removes the product's movement history. */
    public function deleteProduct(string $id): void {
        unset($this->products[$id]);
        $this->movements = array_values(array_filter($this->movements, fn($m) => $m->productId !== $id));
        $this->persist();
    }

    /** $type is 'in' or 'out'. Updates the product's quantity and logs the movement. */
    public function recordMovement(string $productId, string $type, string $quantity, string $note = '', string $date = ''): void {
        $product = $this->product($productId) ?? throw new ValidationException('Select a product.');
        if (!in_array($type, ['in', 'out'], true)) {
            throw new ValidationException('Select stock in or stock out.');
        }
        $qty = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($qty === false) {
            throw new ValidationException('Quantity must be a whole number, 1 or more.');
        }
        if ($type === 'out' && $qty > $product->quantity) {
            throw new ValidationException("Only {$product->quantity} in stock.");
        }

        $product->quantity += $type === 'in' ? $qty : -$qty;
        $this->movements[] = new StockMovement(
            $this->nextMovementId++, $productId, $type, $qty, trim($note), $date ?: date('Y-m-d H:i')
        );
        $this->persist();
    }

    /** The whole inventory as the bytes of a save file. */
    public function export(): string {
        return $this->store->bytes($this->tables());
    }

    /** Replaces everything with a save file's content. Throws ValidationException, leaving the saves untouched, if it isn't a valid save. */
    public function import(string $bytes): void {
        try {
            $this->hydrate($this->store->decode($bytes));
        } catch (Throwable) {
            throw new ValidationException('That file is not a valid inventory save, or it was edited.');
        }
        $this->store->replace($bytes);
    }

    /** Deletes every save file. The next request starts with an empty inventory. */
    public function wipe(): void {
        $this->store->wipe();
    }

    private function persist(): void {
        $this->store->write($this->tables());
    }

    /** The model as normalized tables. Stock levels are not stored: they are the sum of each product's movements. */
    private function tables(): array {
        foreach ($this->products as $p) {   // register categories seen for the first time
            if ($p->category !== '' && !in_array($p->category, $this->categories, true)) {
                $this->categories[max(array_keys($this->categories) ?: [0]) + 1] = $p->category;
            }
        }
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
        $ok(($t['version'] ?? null) === 1);
        $this->categories = $this->products = $this->movements = [];
        $this->nextMovementId = 1;

        foreach ($t['categories'] as $c) {
            $ok(is_int($c['id']) && $c['id'] > 0 && !isset($this->categories[$c['id']])
                && is_string($c['name']) && $c['name'] !== '' && !in_array($c['name'], $this->categories, true));
            $this->categories[$c['id']] = $c['name'];
        }
        foreach ($t['products'] as $r) {
            $ok(is_string($r['id']) && $r['id'] !== '' && !isset($this->products[$r['id']])
                && is_string($r['name']) && $r['name'] !== ''
                && ($r['category_id'] === null || isset($this->categories[$r['category_id']]))
                && is_int($r['reorder_level']) && $r['reorder_level'] >= 0);
            $this->products[$r['id']] = new Product($r['id'], $r['name'], $this->categories[$r['category_id']] ?? '', $r['reorder_level']);
        }
        foreach ($t['movements'] as $r) {   // oldest first, so stock is rebuilt in the order it really changed
            $p = $this->products[$r['product_id']] ?? null;
            $ok($p && is_int($r['id']) && $r['id'] >= $this->nextMovementId
                && in_array($r['type'], ['in', 'out'], true) && is_int($r['quantity']) && $r['quantity'] >= 1
                && is_string($r['note']) && is_string($r['date']) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $r['date']));
            $p->quantity += $r['type'] === 'in' ? $r['quantity'] : -$r['quantity'];
            $ok($p->quantity >= 0);
            $this->movements[] = new StockMovement($r['id'], $p->id, $r['type'], $r['quantity'], $r['note'], $r['date']);
            $this->nextMovementId = $r['id'] + 1;
        }
        return true;
    }
}
