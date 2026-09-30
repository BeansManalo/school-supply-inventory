<?php
require __DIR__ . '/includes/bootstrap.php';

$title = 'Reports';
$restock = array_filter($inventory->products(), fn($p) => $p->status() !== 'OK');
usort($restock, fn($a, $b) => $a->quantity <=> $b->quantity);   // out of stock first
include __DIR__ . '/includes/header.php';
?>
<h2>Needs restock <span class="hint">(<?= count($restock) ?>)</span></h2>
<?php if (!$restock): ?>
    <p class="empty">Everything is stocked.</p>
<?php endif; ?>
<div class="packs">
    <?php foreach ($restock as $p): ?>
        <?php include __DIR__ . '/includes/pack.php'; ?>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/includes/movement_preview.php'; ?>
<?php include __DIR__ . '/includes/report_dialog.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
