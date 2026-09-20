<?php
require __DIR__ . '/includes/bootstrap.php';

$title = 'Dashboard';
$summary = $inventory->summary();
include __DIR__ . '/includes/header.php';
?>
<div class="cards">
    <div class="card"><div class="value"><?= $summary['products'] ?></div><div class="label">Products</div></div>
    <div class="card"><div class="value"><?= $summary['units'] ?></div><div class="label">Units on hand</div></div>
    <div class="card warn"><div class="value"><?= $summary['low'] ?></div><div class="label">Low stock</div></div>
    <div class="card warn"><div class="value"><?= $summary['out'] ?></div><div class="label">Out of stock</div></div>
</div>

<h2>Quick actions</h2>
<div class="actions">
    <a class="btn" href="product_form.php">+ Add product</a>
    <a class="btn btn-secondary" href="stock.php">Record stock in/out</a>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
