<?php
require __DIR__ . '/includes/bootstrap.php';

$title = 'Reports';
$restock = array_filter($inventory->products(), fn($p) => $p->status() !== 'OK');
$rows = $inventory->movements();
include __DIR__ . '/includes/header.php';
?>
<h2>Needs restock</h2>
<table>
    <thead>
        <tr><th>Name</th><th>Category</th><th class="num">On hand</th><th class="num">Reorder level</th><th>Status</th></tr>
    </thead>
    <tbody>
        <?php foreach ($restock as $p): ?>
            <tr>
                <td><?= e($p->name) ?></td>
                <td><?= e($p->category) ?></td>
                <td class="num"><?= $p->quantity ?></td>
                <td class="num"><?= $p->reorderLevel ?></td>
                <td><span class="badge st-<?= strtolower($p->status()) ?>"><?= e($p->status()) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$restock): ?>
            <tr><td colspan="5" class="empty">Everything is stocked.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<h2>Movement history</h2>
<?php include __DIR__ . '/includes/movements_table.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
