<?php
/** One stock-in or stock-out entry. $type is 'in' or 'out'. */
class StockMovement {
    public function __construct(
        public int $id,
        public int $productId,
        public string $type,
        public int $quantity,
        public string $note,
        public string $date,
    ) {}
}
