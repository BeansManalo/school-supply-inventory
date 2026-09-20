<?php
/**
 * The single data store for products and stock movements.
 *
 * Kept in the PHP session for now (see includes/bootstrap.php). When SQL is
 * added, only the method bodies here change -- the pages just call these methods.
 */
class Inventory {
    private array $products = [];   // id => Product
    private array $movements = [];  // StockMovement list, oldest first
    private int $nextProductId = 1;
    private int $nextMovementId = 1;

    public function __construct() {
        $this->seed();
    }

    public function products(): array {
        return $this->products;
    }

    public function product(int $id): ?Product {
        return $this->products[$id] ?? null;
    }

    /** Newest first. */
    public function movements(): array {
        return array_reverse($this->movements);
    }

    /** Counts for the dashboard. */
    public function summary(): array {
        $status = array_count_values(array_map(fn($p) => $p->status(), $this->products));
        return [
            'products' => count($this->products),
            'units'    => array_sum(array_map(fn($p) => $p->quantity, $this->products)),
            'low'      => $status['Low'] ?? 0,
            'out'      => $status['Out'] ?? 0,
        ];
    }

    /** Adds a product when $id is null, otherwise edits it. Inputs are raw form strings. */
    public function saveProduct(?int $id, string $name, string $category, string $reorderLevel): void {
        $name = trim($name);
        $category = trim($category);
        if ($name === '' || $category === '') {
            throw new ValidationException('Name and category are required.');
        }
        $level = filter_var($reorderLevel, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($level === false) {
            throw new ValidationException('Reorder level must be a whole number, 0 or more.');
        }

        if ($id === null) {
            $id = $this->nextProductId++;
            $this->products[$id] = new Product($id, $name, $category, $level);
            return;
        }
        $product = $this->product($id) ?? throw new ValidationException('Product not found.');
        $product->name = $name;
        $product->category = $category;
        $product->reorderLevel = $level;
    }

    /** Also removes the product's movement history. */
    public function deleteProduct(int $id): void {
        unset($this->products[$id]);
        $this->movements = array_values(array_filter($this->movements, fn($m) => $m->productId !== $id));
    }

    /** $type is 'in' or 'out'. Updates the product's quantity and logs the movement. */
    public function recordMovement(int $productId, string $type, string $quantity, string $note = ''): void {
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
            $this->nextMovementId++, $productId, $type, $qty, trim($note), date('Y-m-d H:i')
        );
    }

    /** Sample data so the pages aren't empty. Remove this and the constructor call when SQL is added. */
    private function seed(): void {
        foreach ([['Notebook (80 leaves)', 'Paper', '20'], ['Ballpen (blue)', 'Writing', '30'],
                  ['Pencil #2', 'Writing', '30'], ['Bond paper (short)', 'Paper', '10']] as [$name, $category, $level]) {
            $this->saveProduct(null, $name, $category, $level);
        }
        $this->recordMovement(1, 'in', '100', 'Initial stock');
        $this->recordMovement(1, 'out', '15', 'Issued to Grade 7');
        $this->recordMovement(2, 'in', '25', 'Initial stock');
        $this->recordMovement(4, 'in', '40', 'Initial stock');
    }
}
