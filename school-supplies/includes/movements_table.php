<?php // Expects $rows (StockMovement list) and $inventory. ?>
<table>
    <thead>
        <tr><th>Date</th><th>Product</th><th>Type</th><th class="num">Qty</th><th>Note</th></tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $m): ?>
            <tr>
                <td><?= e($m->date) ?></td>
                <td><?= e($inventory->product($m->productId)?->name) ?></td>
                <td><span class="badge mv-<?= $m->type ?>"><?= $m->type === 'in' ? 'Stock in' : 'Stock out' ?></span></td>
                <td class="num"><?= $m->quantity ?></td>
                <td><?= e($m->note) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="empty">No movements yet.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
