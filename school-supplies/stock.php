<?php
require __DIR__ . '/includes/bootstrap.php';

$form = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST; // keep what the user typed if validation fails
    try {
        $inventory->recordMovement(
            (int) ($_POST['product_id'] ?? 0), $_POST['type'] ?? '', $_POST['quantity'] ?? '', $_POST['note'] ?? ''
        );
        redirect('stock.php', 'Stock updated.');
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    }
}

$title = 'Stock In/Out';
$rows = array_slice($inventory->movements(), 0, 10);
include __DIR__ . '/includes/header.php';
?>
<form method="post" class="form">
    <label>Product
        <select name="product_id" required>
            <option value="">Select...</option>
            <?php foreach ($inventory->products() as $p): ?>
                <option value="<?= $p->id ?>" <?= (int) ($form['product_id'] ?? 0) === $p->id ? 'selected' : '' ?>>
                    <?= e($p->name) ?> (<?= $p->quantity ?> on hand)
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Type
        <select name="type">
            <option value="in" <?= ($form['type'] ?? '') === 'in' ? 'selected' : '' ?>>Stock in</option>
            <option value="out" <?= ($form['type'] ?? '') === 'out' ? 'selected' : '' ?>>Stock out</option>
        </select>
    </label>
    <label>Quantity
        <input type="number" name="quantity" min="1" value="<?= e($form['quantity'] ?? '') ?>" required>
    </label>
    <label>Note <span class="hint">(optional)</span>
        <input type="text" name="note" value="<?= e($form['note'] ?? '') ?>">
    </label>
    <div class="actions"><button class="btn">Record</button></div>
</form>

<h2>Recent movements</h2>
<?php include __DIR__ . '/includes/movements_table.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
