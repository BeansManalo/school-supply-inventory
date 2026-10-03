<?php
require __DIR__ . '/includes/bootstrap.php';

$grouped = ($_GET['group'] ?? '') === 'category';
$self = 'products.php' . ($grouped ? '?group=category' : '');   // keeps the grouping after a save/delete

$form = [];   // stock dialog values, kept if validation fails
$stockError = null;
$add = [];    // add dialog values, kept if validation fails
$addError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::need(isset($_POST['delete_id']) || isset($_POST['add']) || !isset($_POST['quantity']) ? 'products' : 'stock');   // the same order as the branches below
    if (isset($_POST['delete_id'])) {
        $inventory->deleteProduct($_POST['delete_id']);
        redirect($self, 'Product deleted.');
    }
    try {
        if (isset($_POST['add'])) {   // the add dialog
            $add = $_POST;
            $inventory->saveProduct(true, $_POST['id'] ?? '', $_POST['name'] ?? '', $_POST['category'] ?? '', $_POST['reorder_level'] ?? '');
            redirect($self, 'Product added.');
        }
        if (isset($_POST['quantity'])) {   // the stock in/out dialog
            $form = $_POST;
            $inventory->recordMovement($_POST['product_id'] ?? '', $_POST['type'] ?? '', $_POST['quantity'], $_POST['note'] ?? '');
            redirect($self, 'Stock updated.');
        }
        $inventory->saveProduct(false, $_POST['product_id'] ?? '', $_POST['name'] ?? '', $_POST['category'] ?? '', $_POST['reorder_level'] ?? '');
        redirect($self, 'Product updated.');
    } catch (ValidationException $e) {
        if (isset($_POST['add'])) {
            $addError = $e->getMessage();
        } elseif (isset($_POST['quantity'])) {
            $stockError = $e->getMessage();
        } else {
            $error = $e->getMessage();
        }
    }
}

$products = $inventory->products();
$categories = array_unique(array_filter(array_column($products, 'category')));
sort($categories, SORT_FLAG_CASE | SORT_STRING);
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
    <div class="filters">
        <input type="search" placeholder="Search products..." data-filter="products">
        <select data-view="category" aria-label="Category">
            <option value="*">All categories</option>
            <option value="">No category</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>"><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
        <select data-view="status" aria-label="Status">
            <option value="*">Any status</option>
            <option value="out">Out of stock</option>
            <option value="low">Low</option>
            <option value="ok">OK</option>
        </select>
        <select data-view="sort" aria-label="Sort by">
            <option value="">Sort: order added</option>
            <option value="name">Name A-Z</option>
            <option value="name_desc">Name Z-A</option>
            <option value="stock">Stock: low to high</option>
            <option value="stock_desc">Stock: high to low</option>
            <option value="category">Category A-Z</option>
            <option value="id">Product ID</option>
        </select>
        <form>
            <label class="check">
                <input type="checkbox" name="group" value="category" onchange="this.form.submit()" <?= $grouped ? 'checked' : '' ?>>
                Group by category
            </label>
        </form>
    </div>
    <?php if (Auth::can('stock') || Auth::can('products')): ?>
    <div class="actions">
        <?php if (Auth::can('stock')): ?><button type="button" class="btn btn-secondary" id="open-stock">Stock in/out</button><?php endif; ?>
        <?php if (Auth::can('products')): ?><button type="button" class="btn" id="open-add">+ Add product</button><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!$products): ?>
    <p class="empty">No products yet.</p>
<?php endif; ?>

<?php $editable = Auth::can('products'); ?>
<div id="products">
    <?php if ($products): ?>
        <p class="empty" data-none hidden>No products match.</p>
    <?php endif; ?>
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
    <?php foreach ($categories as $c): ?>
        <option value="<?= e($c) ?>">
    <?php endforeach; ?>
</datalist>

<?php if (Auth::can('products')): ?>
<?php include __DIR__ . '/includes/add_dialog.php'; ?>
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
<?php endif; ?>
<?php if (Auth::can('stock')): ?>
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
            <input type="text" name="note" maxlength="500" value="<?= e($form['note'] ?? '') ?>">
        </label>
        <div class="actions">
            <button class="btn">Record</button>
            <button class="btn btn-secondary" formmethod="dialog" formnovalidate>Cancel</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
