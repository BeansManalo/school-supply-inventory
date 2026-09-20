<?php
require __DIR__ . '/includes/bootstrap.php';

// Add when there is no ?id=, edit when there is.
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$product = $id !== null ? $inventory->product($id) : null;
if ($id !== null && !$product) {
    redirect('products.php', 'Product not found.');
}

$form = [
    'name'          => $product?->name ?? '',
    'category'      => $product?->category ?? '',
    'reorder_level' => $product?->reorderLevel ?? 0,
];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST; // keep what the user typed if validation fails
    try {
        $inventory->saveProduct($id, $_POST['name'] ?? '', $_POST['category'] ?? '', $_POST['reorder_level'] ?? '');
        redirect('products.php', $id ? 'Product updated.' : 'Product added.');
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    }
}

$title = $id ? 'Edit Product' : 'Add Product';
$active = 'products.php';
include __DIR__ . '/includes/header.php';
?>
<form method="post" class="form">
    <label>Name
        <input type="text" name="name" value="<?= e($form['name'] ?? '') ?>" required>
    </label>
    <label>Category
        <input type="text" name="category" value="<?= e($form['category'] ?? '') ?>" required>
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
