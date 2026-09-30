<?php
/** A school supply item. Quantity only changes through Inventory::recordMovement(). */
class Product {
    public function __construct(
        public string $id,
        public string $name,
        public string $category,
        public int $reorderLevel = 0,
        public int $quantity = 0,
    ) {}

    /** 'Out', 'Low' or 'OK' */
    public function status(): string {
        return $this->quantity <= 0 ? 'Out' : ($this->quantity <= $this->reorderLevel ? 'Low' : 'OK');
    }
}
