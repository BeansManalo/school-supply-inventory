<?php
require __DIR__ . '/includes/bootstrap.php';

$form = ['id' => '', 'name' => '', 'category' => '', 'reorder_level' => 0];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST; // keep what the user typed if validation fails
    try {
        $inventory->saveProduct(true, $_POST['id'] ?? '', $_POST['name'] ?? '', $_POST['category'] ?? '', $_POST['reorder_level'] ?? '');
        redirect('products.php', 'Product added.');
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    }
}

$title = 'Add Product';
$active = 'products.php';
include __DIR__ . '/includes/header.php';
?>
<form method="post" class="form">
    <label>Product ID <span class="hint">(SKU or scanned barcode; can't be changed later)</span>
        <input type="text" name="id" value="<?= e($form['id'] ?? '') ?>" required>
    </label>
    <label>Name
        <input type="text" name="name" value="<?= e($form['name'] ?? '') ?>" required>
    </label>
    <label>Category <span class="hint">(optional)</span>
        <input type="text" name="category" value="<?= e($form['category'] ?? '') ?>">
    </label>
    <label>Reorder level <span class="hint">(flagged as Low at or below this)</span>
        <input type="number" name="reorder_level" min="0" value="<?= e($form['reorder_level'] ?? 0) ?>" required>
    </label>
    <div class="actions">
        <button class="btn">Save</button>
        <a class="btn btn-secondary" href="products.php">Cancel</a>
    </div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
