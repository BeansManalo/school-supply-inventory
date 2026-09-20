<?php
require __DIR__ . '/includes/bootstrap.php';

// Delete (POST from the row's Delete button)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventory->deleteProduct((int) ($_POST['delete_id'] ?? 0));
    redirect('products.php', 'Product deleted.');
}

$title = 'Products';
include __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <input type="search" placeholder="Search products..." data-filter="products-table">
    <a class="btn" href="product_form.php">+ Add product</a>
</div>

<table id="products-table">
    <thead>
        <tr><th>Name</th><th>Category</th><th class="num">On hand</th><th class="num">Reorder level</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
        <?php foreach ($inventory->products() as $p): ?>
            <tr>
                <td><?= e($p->name) ?></td>
                <td><?= e($p->category) ?></td>
                <td class="num"><?= $p->quantity ?></td>
                <td class="num"><?= $p->reorderLevel ?></td>
                <td><span class="badge st-<?= strtolower($p->status()) ?>"><?= e($p->status()) ?></span></td>
                <td class="num">
                    <a href="product_form.php?id=<?= $p->id ?>">Edit</a>
                    <form method="post" class="inline" data-confirm="Delete this product and its stock history?">
                        <input type="hidden" name="delete_id" value="<?= $p->id ?>">
                        <button class="btn btn-link danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$inventory->products()): ?>
            <tr><td colspan="6" class="empty">No products yet.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
<?php include __DIR__ . '/includes/footer.php'; ?>
