<?php
require __DIR__ . '/includes/bootstrap.php';

$grouped = ($_GET['group'] ?? '') === 'category';
$self = 'products.php' . ($grouped ? '?group=category' : '');   // keeps the grouping after a save/delete

$form = [];   // stock dialog values, kept if validation fails
$stockError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $inventory->deleteProduct($_POST['delete_id']);
        redirect($self, 'Product deleted.');
    }
    try {
        if (isset($_POST['quantity'])) {   // the stock in/out dialog
            $form = $_POST;
            $inventory->recordMovement($_POST['product_id'] ?? '', $_POST['type'] ?? '', $_POST['quantity'], $_POST['note'] ?? '');
            redirect($self, 'Stock updated.');
        }
        $inventory->saveProduct(false, $_POST['product_id'] ?? '', $_POST['name'] ?? '', $_POST['category'] ?? '', $_POST['reorder_level'] ?? '');
        redirect($self, 'Product updated.');
    } catch (ValidationException $e) {
        if (isset($_POST['quantity'])) {
            $stockError = $e->getMessage();
        } else {
            $error = $e->getMessage();
        }
    }
}

$products = $inventory->products();
$groups = ['' => $products];
if ($grouped) {
    $groups = [];
    foreach ($products as $p) {
        $groups[$p->category][] = $p;
    }
    // A-Z, with the uncategorized ('') group last.
    uksort($groups, fn($a, $b) => ($a === '') <=> ($b === '') ?: strcasecmp($a, $b));
}

$title = 'Products';
include __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <input type="search" placeholder="Search products..." data-filter="products">
    <form>
        <label class="check">
            <input type="checkbox" name="group" value="category" onchange="this.form.submit()" <?= $grouped ? 'checked' : '' ?>>
            Group by category
        </label>
    </form>
    <div class="actions">
        <button type="button" class="btn btn-secondary" id="open-stock">Stock in/out</button>
        <a class="btn" href="product_form.php">+ Add product</a>
    </div>
</div>

<?php if (!$products): ?>
    <p class="empty">No products yet.</p>
<?php endif; ?>

<?php $editable = true; ?>
<div id="products">
    <?php foreach ($groups as $category => $items): ?>
        <section class="group">
            <?php if ($grouped): ?>
                <h2><?= $category === '' ? 'No category' : e($category) ?></h2>
            <?php endif; ?>
            <div class="packs">
                <?php foreach ($items as $p): ?>
                    <?php include __DIR__ . '/includes/pack.php'; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<datalist id="categories">
    <?php foreach (array_unique(array_filter(array_column($products, 'category'))) as $c): ?>
        <option value="<?= e($c) ?>">
    <?php endforeach; ?>
</datalist>

<dialog id="product-dialog" class="form">
    <h2>Edit product</h2>
    <form method="post">
        <label>Product ID
            <input type="text" name="product_id" readonly>
        </label>
        <label>Name
            <input type="text" name="name" required>
        </label>
        <label>Category <span class="hint">(optional)</span>
            <input type="text" name="category" list="categories">
        </label>
        <label>Reorder level <span class="hint">(flagged as Low at or below this)</span>
            <input type="number" name="reorder_level" min="0" required>
        </label>
        <div class="actions">
            <button class="btn">Save</button>
            <button class="btn btn-secondary" formmethod="dialog" formnovalidate>Discard</button>
            <button class="btn btn-danger" form="delete-form">Delete</button>
        </div>
    </form>
    <form id="delete-form" method="post" data-confirm="Delete this product and its stock history?">
        <input type="hidden" name="delete_id">
    </form>
</dialog>
<dialog id="stock-dialog" class="form" <?= $stockError || isset($_GET['stock']) ? 'data-open' : '' ?>>
    <h2>Stock in/out</h2>
    <?php if ($stockError): ?>
        <div class="alert alert-error"><?= e($stockError) ?></div>
    <?php endif; ?>
    <form method="post">
        <label>Product
            <select name="product_id" required>
                <option value="">Select...</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= e($p->id) ?>" <?= ($form['product_id'] ?? '') === $p->id ? 'selected' : '' ?>>
                        <?= e($p->name) ?> (<?= $p->quantity ?> on hand)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="seg">
            <label><input type="radio" name="type" value="in" <?= ($form['type'] ?? 'in') === 'in' ? 'checked' : '' ?>> Stock in</label>
            <label><input type="radio" name="type" value="out" <?= ($form['type'] ?? '') === 'out' ? 'checked' : '' ?>> Stock out</label>
        </div>
        <label>Quantity
            <input type="number" name="quantity" min="1" value="<?= e($form['quantity'] ?? '') ?>" required>
        </label>
        <label>Note <span class="hint">(optional)</span>
            <input type="text" name="note" value="<?= e($form['note'] ?? '') ?>">
        </label>
        <div class="actions">
            <button class="btn">Record</button>
            <button class="btn btn-secondary" formmethod="dialog" formnovalidate>Cancel</button>
        </div>
    </form>
</dialog>
<?php include __DIR__ . '/includes/footer.php'; ?>
