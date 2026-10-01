<?php // The "Add product" popup. Posts to products.php, which reopens it with the message if the product is refused. Optional: $add (values to keep), $addError. ?>
<dialog id="add-dialog" class="form" <?= ($addError ?? null) || isset($_GET['add']) ? 'data-open' : '' ?>>
    <h2>Add product</h2>
    <?php if ($addError ?? null): ?>
        <div class="alert alert-error"><?= e($addError) ?></div>
    <?php endif; ?>
    <form method="post" action="products.php">
        <input type="hidden" name="add" value="1">
        <label>Product ID <span class="hint">(SKU or scanned barcode; can't be changed later)</span>
            <input type="text" name="id" value="<?= e($add['id'] ?? '') ?>" required>
        </label>
        <label>Name
            <input type="text" name="name" value="<?= e($add['name'] ?? '') ?>" required>
        </label>
        <label>Category <span class="hint">(optional)</span>
            <input type="text" name="category" list="categories" value="<?= e($add['category'] ?? '') ?>">
        </label>
        <label>Reorder level <span class="hint">(flagged as Low at or below this)</span>
            <input type="number" name="reorder_level" min="0" value="<?= e($add['reorder_level'] ?? 0) ?>" required>
        </label>
        <div class="actions">
            <button class="btn">Save</button>
            <button class="btn btn-secondary" formmethod="dialog" formnovalidate>Cancel</button>
        </div>
    </form>
</dialog>
